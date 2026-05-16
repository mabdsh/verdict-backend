<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\GroqApiException;
use App\Exceptions\GroqParseException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * Groq API client.
 *
 * Port of the Node backend's services/groqClient.ts. Uses Laravel's Http
 * facade directly rather than a third-party SDK — Groq's API is OpenAI-
 * compatible (POST JSON to /chat/completions), so wrapping a community
 * package adds dependency risk without saving meaningful code.
 *
 * Three public methods, one per use case:
 *   batchScoreJobs() — score a batch of job cards (SMART model)
 *   analyzeJob()     — deep analysis of one job + full JD (SMART model)
 *   parseProfile()   — extract structured prefs from prose (FAST model)
 *
 * Each method:
 *   1. Builds the request body inline (prompts are intentionally inline —
 *      they're tightly coupled to the JSON-shape expected from each call).
 *   2. Calls callGroq() which handles retry/timeout uniformly.
 *   3. Parses the response as JSON and validates the shape.
 *   4. Throws GroqParseException on bad JSON, GroqApiException on HTTP error.
 *
 * Callers in controllers (P6+) catch these exceptions and map to user
 * responses with error='GROQ_PARSE_ERROR' or 'GROQ_RATE_LIMIT' to match the
 * Node response shapes the extension already handles.
 *
 * Configuration (from config/verdict.php):
 *   groq.api_key       — secret API key
 *   groq.model_smart   — llama-3.3-70b-versatile (default)
 *   groq.model_fast    — llama-3.1-8b-instant (default)
 *   groq.base_url      — https://api.groq.com/openai/v1 (default)
 *   groq.timeout       — 30s default
 */
final class GroqService
{
    /**
     * Retry delays in milliseconds. Same as the Node backend (3 attempts,
     * [150ms, 400ms] between them). Conservative because Groq rate-limit
     * responses already include a Retry-After hint; aggressive retries
     * would defeat their backpressure.
     */
    private const RETRY_DELAYS_MS = [150, 400];

    // ────────────────────────────────────────────────────────────────────────
    // Public API
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Score a batch of jobs.
     *
     * @param  array<string, mixed>  $profile  Candidate profile
     * @param  list<array<string, mixed>>  $jobs  Jobs to score (caller ensures count > 0 && ≤ MAX_JOBS_PER_BATCH)
     * @return list<array{jobId: string, score: int, label: string, text: string, verdict: string}>
     *
     * @throws GroqParseException
     * @throws GroqApiException
     */
    public function batchScoreJobs(array $profile, array $jobs): array
    {
        if (empty($jobs)) {
            return [];
        }

        $summary  = $this->buildProfileSummary($profile);
        $jobLines = $this->buildJobLines($jobs);

        $systemPrompt = <<<'PROMPT'
You are an expert recruiter scoring job-candidate fit. Be honest and calibrated.

Score guide: 85-100=exceptional, 70-84=strong, 50-69=reasonable, 30-49=weak, 0-29=poor.
Label rules: green≥70, amber 50-69, red <50.
Hard rules:
- If candidate's critical/must-have skills are absent from the job title: cap score at 55
- If job contains any candidate deal-breakers: score must be 0-15
- Generic titles (just "Software Engineer") with no tech stack visible: score 25-40 unless skills strongly align

Return ONLY valid JSON (no markdown):
{
  "results": [
    {"jobId":"<id>","score":<0-100>,"label":"<green|amber|red>","text":"<Exceptional fit|Strong match|Good match|Partial match|Weak fit|Poor fit>","verdict":"<one specific sentence>"},
    ...
  ]
}
Return one entry per job in the same order, no extra fields.
PROMPT;

        $userPrompt = "CANDIDATE:\n{$summary}\n\nJOBS TO SCORE:\n{$jobLines}";

        $response = $this->callGroq([
            'model'           => (string) config('verdict.groq.model_smart'),
            'temperature'     => 0.1,
            // Match the Node calculation: 200 + jobs.length * 60, capped at 1800.
            'max_tokens'      => min(200 + count($jobs) * 60, 1800),
            'response_format' => ['type' => 'json_object'],
            'messages'        => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ],
        ]);

        $parsed = $this->parseContent($response);

        // Defensive: results key is what we asked for, but the model can
        // occasionally return the array at the top level. Handle both.
        $results = $parsed['results'] ?? (array_is_list($parsed) ? $parsed : []);
        return is_array($results) ? array_values($results) : [];
    }

    /**
     * Deep analysis of a single job.
     *
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $jobData
     * @return array{decision: string, summary: string, keyRequirements: array<int, string>, strengths: array<int, string>, gaps: array<int, string>, tips: array<int, string>, insights: string}
     *
     * @throws GroqParseException
     * @throws GroqApiException
     */
    public function analyzeJob(array $profile, array $jobData, string $fullDescription): array
    {
        $summary = $this->buildProfileSummary($profile);

        $jobText = $this->buildJobAnalysisText($jobData, $fullDescription);

        $systemPrompt = <<<'PROMPT'
You are a senior career coach helping a candidate decide whether to apply and how to win this specific role.
Be direct, specific, and tactical. Name actual technologies and requirements. Never give generic advice.
Think like someone who wants this candidate to succeed.

Return ONLY valid JSON:
{
  "decision":        "<one clear verdict — e.g. 'Apply — your Laravel+Vue directly matches their core stack' or 'Stretch — worth applying if you lead with your API work'>",
  "summary":         "<2 sentences: what this role actually involves day-to-day + how well the candidate fits. Be specific about the real tech and team context.>",
  "keyRequirements": ["<top 3 things this job is actually hiring for — pulled verbatim or closely from the description>"],
  "strengths":       ["<2-3 specific reasons this candidate stands out for THIS role — name the matching tech/experience and why it matters to this employer>"],
  "gaps":            ["<gap description — address it by: specific tactic for this application>"],
  "tips":            ["<Cover letter: specific angle to lead with for this role and company>", "<CV: one specific reordering or emphasis change for this application>", "<Interview: one likely question based on the gaps or role complexity>"],
  "insights":        "<one honest, non-obvious coaching observation — could be about role fit, company signal, salary vs market, team dynamics, or a hidden opportunity in the JD>"
}

For gaps: phrase as "No [skill] mentioned — address it by: [specific action]". Use [] if strong match with no real gaps.
For tips: be role-specific. Reference actual requirements from the JD. No phrases like "tailor your resume" or "highlight relevant experience".
PROMPT;

        $userPrompt = "CANDIDATE:\n{$summary}\n\n---\n\n{$jobText}";

        $response = $this->callGroq([
            'model'           => (string) config('verdict.groq.model_smart'),
            'temperature'     => 0.1,
            'max_tokens'      => 1100,
            'response_format' => ['type' => 'json_object'],
            'messages'        => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ],
        ]);

        return $this->parseContent($response);
    }

    /**
     * Extract structured job-search preferences from a freeform description.
     *
     * Uses the FAST model (llama-3.1-8b-instant) — extraction tasks don't
     * need the SMART model's reasoning quality.
     *
     * @return array{currentTitle: string, experienceYears: int, targetRoles: array<int, string>, workTypes: array<int, string>, jobTypes: array<int, string>, minSalary: int, mustHaveSkills: array<int, string>, primarySkills: array<int, string>, secondarySkills: array<int, string>, dealBreakers: array<int, string>, avoidIndustries: array<int, string>, careerGoal: string}
     *
     * @throws GroqParseException
     * @throws GroqApiException
     */
    public function parseProfile(string $text): array
    {
        $systemPrompt = <<<'PROMPT'
Extract job search preferences from this description.
Return ONLY valid JSON:
{
  "currentTitle":     "<role or ''>",
  "experienceYears":  <0-30>,
  "targetRoles":      ["<2-5 job titles>"],
  "workTypes":        ["<subset of: remote, hybrid, onsite>"],
  "jobTypes":         ["<subset of: full-time, contract, part-time>"],
  "minSalary":        <annual USD or 0>,
  "mustHaveSkills":   ["<2-4 absolutely critical hard skills — dealbreaker if absent>"],
  "primarySkills":    ["<expert-level hard skills>"],
  "secondarySkills":  ["<familiar but not expert>"],
  "dealBreakers":     ["<tech/domains strictly to avoid>"],
  "avoidIndustries":  ["<industries to avoid>"],
  "careerGoal":       "<1 sentence or ''>"
}
Rules: mustHaveSkills = 2-4 skills the person MUST see in the job. No soft skills anywhere. Use [] for anything not mentioned.
PROMPT;

        $response = $this->callGroq([
            'model'           => (string) config('verdict.groq.model_fast'),
            'temperature'     => 0.1,
            'max_tokens'      => 500,
            'response_format' => ['type' => 'json_object'],
            'messages'        => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => trim($text)],
            ],
        ]);

        return $this->parseContent($response);
    }

    // ────────────────────────────────────────────────────────────────────────
    // HTTP layer
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Make a chat-completions request to Groq with retry on transient errors.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>  Decoded JSON response
     *
     * @throws GroqApiException
     */
    private function callGroq(array $body): array
    {
        $baseUrl = (string) config('verdict.groq.base_url', 'https://api.groq.com/openai/v1');
        $apiKey  = (string) config('verdict.groq.api_key');
        $timeout = (int) config('verdict.groq.timeout', 30);

        try {
            $response = $this->client($apiKey, $timeout)
                ->retry(
                    times: 3,
                    sleepMilliseconds: function (int $attempt) {
                        // $attempt is 1-indexed in Laravel's retry callback.
                        // First retry sleeps RETRY_DELAYS_MS[0], second [1].
                        return self::RETRY_DELAYS_MS[$attempt - 1] ?? self::RETRY_DELAYS_MS[count(self::RETRY_DELAYS_MS) - 1];
                    },
                    when: $this->retryWhen(...),
                )
                ->post("{$baseUrl}/chat/completions", $body);
        } catch (ConnectionException $e) {
            // Connection-level errors after retries — surface as 503-equivalent.
            throw new GroqApiException(503, $e->getMessage(), $e);
        } catch (RequestException $e) {
            // HTTP error after retries exhausted. Laravel re-throws this
            // automatically when the response has thrown previously OR when
            // retry() is given the default $throw=true. We catch and rewrap
            // so callers only see our domain exception type.
            $resp = $e->response;
            throw new GroqApiException($resp->status(), (string) $resp->body(), $e);
        }

        if (! $response->successful()) {
            // Reached when retry exhausted without throw (e.g. non-retryable
            // 4xx). Wrap as our domain exception.
            $this->logHttpError($response);
            throw new GroqApiException($response->status(), (string) $response->body());
        }

        try {
            return $response->json() ?? [];
        } catch (JsonException $e) {
            throw new GroqParseException((string) $response->body(), $e);
        }
    }

    /**
     * Retry policy: 429 (rate-limited) + 5xx (server errors) + connection
     * errors. NEVER retry 4xx other than 429 — that means we sent bad input
     * and retrying will get the same response.
     *
     * Type-hint Throwable rather than Exception — there's a known Laravel
     * bug (issue #59012) where the `when` callback receives a TypeError
     * subclass on 3xx responses, and TypeError doesn't extend Exception in
     * PHP. Throwable is the common ancestor and is always safe.
     */
    private function retryWhen(\Throwable $exception, PendingRequest $request): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }
        if (! method_exists($exception, 'response')) {
            return false;
        }
        /** @var Response|null $response */
        $response = $exception->response();
        if ($response === null) {
            return true; // unknown error, give it a chance
        }
        $status = $response->status();
        return $status === 429 || ($status >= 500 && $status < 600);
    }

    /**
     * Build a base HTTP client with auth + JSON content type.
     */
    private function client(string $apiKey, int $timeoutSeconds): PendingRequest
    {
        return Http::baseUrl((string) config('verdict.groq.base_url'))
            ->withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout($timeoutSeconds);
    }

    private function logHttpError(Response $response): void
    {
        Log::warning('[Groq] HTTP error', [
            'status' => $response->status(),
            // Truncate body in logs — Groq errors can be 1-2 KB and we don't
            // want to spam the log with prompt content.
            'body'   => substr((string) $response->body(), 0, 500),
        ]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Content parsing
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Pluck `choices[0].message.content` from a Groq response and parse it
     * as JSON. Throws GroqParseException on either missing content or
     * non-JSON content.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     *
     * @throws GroqParseException
     */
    private function parseContent(array $response): array
    {
        $raw = $response['choices'][0]['message']['content'] ?? null;

        if (! is_string($raw) || $raw === '') {
            throw new GroqParseException('(empty content)');
        }

        // Some models still wrap output in ```json fences despite the
        // response_format: json_object hint. Strip them defensively.
        $cleaned = preg_replace('/```json/i', '', $raw) ?? $raw;
        $cleaned = str_replace('```', '', $cleaned);
        $cleaned = trim($cleaned);

        try {
            $decoded = json_decode($cleaned, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new GroqParseException($raw, $e);
        }

        if (! is_array($decoded)) {
            throw new GroqParseException($raw);
        }

        return $decoded;
    }

    // ────────────────────────────────────────────────────────────────────────
    // Profile / job summarisation
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Compact textual summary of a candidate profile for the LLM prompt.
     *
     * Port of buildProfileSummary() from groqClient.ts. Same line layout
     * because LLM output stability is sensitive to whitespace + ordering.
     *
     * @param  array<string, mixed>|null  $profile
     */
    private function buildProfileSummary(?array $profile): string
    {
        if ($profile === null || empty($profile)) {
            return 'No profile set';
        }

        $lines = [];

        $add = function (string $line) use (&$lines): void {
            if ($line !== '') {
                $lines[] = $line;
            }
        };

        $add($profile['currentTitle']    ?? null ? 'Current role: ' . $profile['currentTitle'] : '');
        $add($profile['experienceYears'] ?? null ? 'Experience: ' . $profile['experienceYears'] . ' years' : '');

        $add($this->listLine('Target roles',                  $profile['targetRoles']     ?? []));
        $add($this->listLine('CRITICAL skills (must appear)', $profile['mustHaveSkills']  ?? []));
        $add($this->listLine('Expert skills',                 $profile['primarySkills']   ?? []));
        $add($this->listLine('Also know',                     $profile['secondarySkills'] ?? []));
        $add($this->listLine('Work preference',               $profile['workTypes']       ?? []));

        if (($profile['minSalary'] ?? 0) > 1000) {
            $add('Min salary: $' . $this->formatK((int) $profile['minSalary']) . '/yr');
        }

        $add($this->listLine('Hard no',          $profile['dealBreakers']    ?? []));
        $add($this->listLine('Avoid industries', $profile['avoidIndustries'] ?? []));

        if (! empty($profile['careerGoal'])) {
            $add('Goal: ' . $profile['careerGoal']);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, string>|mixed  $values
     */
    private function listLine(string $label, mixed $values): string
    {
        if (! is_array($values) || empty($values)) {
            return '';
        }
        return "{$label}: " . implode(', ', $values);
    }

    /**
     * Format an integer as a K-suffixed string. 1000 → '1K', 85000 → '85K'.
     * Values < 1000 stringify directly.
     */
    private function formatK(int $n): string
    {
        if ($n === 0) {
            return '?';
        }
        if ($n >= 1000) {
            return (string) round($n / 1000) . 'K';
        }
        return (string) $n;
    }

    /**
     * Build the indexed-list of jobs for the batch-score prompt.
     *
     * @param  list<array<string, mixed>>  $jobs
     */
    private function buildJobLines(array $jobs): string
    {
        $lines = [];
        foreach ($jobs as $i => $j) {
            $title    = $j['title']    ?? 'Unknown';
            $company  = $j['company']  ?? 'Unknown';
            $workType = $j['workType'] ?? 'work type unknown';

            $salary = 'salary not listed';
            if (isset($j['salary']) && is_array($j['salary'])) {
                $low  = $this->formatK((int) ($j['salary']['low']  ?? 0));
                $high = $this->formatK((int) ($j['salary']['high'] ?? 0));
                $salary = "\${$low}–\${$high}/yr";
            }

            $jobId = $j['jobId'] ?? (string) $i;
            $n     = $i + 1;
            $lines[] = "{$n}. [id:{$jobId}] {$title} at {$company} · {$workType} · {$salary}";
        }
        return implode("\n", $lines);
    }

    /**
     * Format a single job for the analyzeJob prompt.
     *
     * @param  array<string, mixed>  $jobData
     */
    private function buildJobAnalysisText(array $jobData, string $fullDescription): string
    {
        $lines = [
            'Title: '     . ($jobData['title']    ?? 'Unknown'),
            'Company: '   . ($jobData['company']  ?? 'Unknown'),
            'Location: '  . ($jobData['location'] ?? 'Not specified'),
            'Work type: ' . ($jobData['workType'] ?? 'Not specified'),
        ];

        if (isset($jobData['salary']) && is_array($jobData['salary'])) {
            $low  = $this->formatK((int) ($jobData['salary']['low']  ?? 0));
            $high = $this->formatK((int) ($jobData['salary']['high'] ?? 0));
            $lines[] = "Salary: \${$low}–\${$high}/yr";
        } else {
            $lines[] = 'Salary: Not listed';
        }

        $lines[] = '';
        $lines[] = 'Full job description:';
        $lines[] = $fullDescription !== ''
            ? substr($fullDescription, 0, 3800)  // same truncation as Node
            : '(not available — scored from card data only)';

        return implode("\n", $lines);
    }
}
