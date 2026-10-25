<?php

namespace App\Traits;

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use Illuminate\Support\Str;

trait IncludesDeploymentCommitDetails
{
    private const SLACK_SECTION_TEXT_LIMIT = 3000;

    public ?string $commit_sha = null;

    public ?string $commit_message = null;

    public ?string $commit_url = null;

    public ?string $commit_branch = null;

    /** Store plain strings from the job's in-memory deployment, so no query runs here. */
    protected function resolveDeploymentCommitDetails(Application $application, ?ApplicationDeploymentQueue $deployment, ?ApplicationPreview $preview = null): void
    {
        if (! $deployment) {
            return;
        }

        $commit = trim((string) $deployment->commit);
        if (preg_match('/^[0-9a-f]{7,64}$/i', $commit) === 1) {
            $this->commit_sha = $commit;
            $this->commit_url = $application->gitCommitLink($commit);
        }

        $message = trim(mb_scrub((string) $deployment->commitMessage(), 'UTF-8'));
        if ($message !== '') {
            $this->commit_message = Str::limit($message, 1000);
        }

        if (is_null($preview) && filled($application->git_branch)) {
            $this->commit_branch = $application->git_branch;
        }
    }

    /** @return array{sha: string|null, short_sha: string|null, url: string|null, branch: string|null, message: string|null}|null */
    protected function deploymentCommitDetailsForMail(?object $notifiable): ?array
    {
        $enabled = (bool) data_get($notifiable, 'emailNotificationSettings.deployment_commit_details_email_notifications', false);

        if (! $enabled || (is_null($this->commit_sha) && is_null($this->commit_message))) {
            return null;
        }

        return [
            'sha' => $this->commit_sha,
            'short_sha' => $this->commit_sha ? substr($this->commit_sha, 0, 7) : null,
            'url' => $this->commit_url,
            'branch' => $this->commit_branch ? self::escapeMailMarkdown($this->commit_branch) : null,
            'message' => $this->commit_message ? self::formatCommitMessageForMail($this->commit_message) : null,
        ];
    }

    /** @return array{sha: string|null, short_sha: string|null, url: string|null, branch: string|null, message: string|null}|null */
    protected function deploymentCommitDetailsForSlack(?object $notifiable): ?array
    {
        $enabled = (bool) data_get($notifiable, 'slackNotificationSettings.deployment_commit_details_slack_notifications', false);

        if (! $enabled || (is_null($this->commit_sha) && is_null($this->commit_message))) {
            return null;
        }

        return [
            'sha' => $this->commit_sha,
            'short_sha' => $this->commit_sha ? substr($this->commit_sha, 0, 7) : null,
            'url' => $this->commit_url,
            'branch' => $this->commit_branch,
            'message' => $this->commit_message,
        ];
    }

    /** Fit commit details into Slack's 3000-character section limit, keeping $suffix. */
    protected function appendSlackCommitDetails(string $description, ?object $notifiable, string $suffix = ''): string
    {
        if (mb_strlen($suffix) >= self::SLACK_SECTION_TEXT_LIMIT) {
            return mb_substr($suffix, -self::SLACK_SECTION_TEXT_LIMIT);
        }

        $description = self::limitSlackText($description, self::SLACK_SECTION_TEXT_LIMIT - mb_strlen($suffix));
        $commit = $this->deploymentCommitDetailsForSlack($notifiable);
        if ($commit === null) {
            return $description.$suffix;
        }

        $available = self::SLACK_SECTION_TEXT_LIMIT - mb_strlen($description.$suffix);
        if ($available <= 0) {
            return $description.$suffix;
        }

        $parts = [];
        $commitLine = $this->slackCommitLine($commit);
        if ($commitLine !== null) {
            $commitBlock = "\n\n".$commitLine;
            if (mb_strlen($commitBlock) <= $available) {
                $parts[] = $commitLine;
                $available -= mb_strlen($commitBlock);
            }
        }

        $message = is_string($commit['message']) ? str_replace('```', "'''", $commit['message']) : '';
        if ($message !== '') {
            $joiner = $parts === [] ? "\n\n" : "\n";
            $prefix = "*Commit message:*\n```\n";
            $closing = "\n```";
            $room = $available - mb_strlen($joiner.$prefix.$closing);
            if ($room > 0) {
                $parts[] = $prefix.self::limitSlackText($message, $room).$closing;
            }
        }

        if ($parts === []) {
            return $description.$suffix;
        }

        return $description."\n\n".implode("\n", $parts).$suffix;
    }

    private static function limitSlackText(string $text, int $limit): string
    {
        if ($limit <= 0) {
            return '';
        }

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $ellipsis = '...';
        if ($limit <= mb_strlen($ellipsis)) {
            return mb_substr($text, 0, $limit);
        }

        return mb_substr($text, 0, $limit - mb_strlen($ellipsis)).$ellipsis;
    }

    /** @param array{sha: string|null, short_sha: string|null, url: string|null, branch: string|null, message: string|null} $commit */
    private function slackCommitLine(array $commit): ?string
    {
        if (! $commit['short_sha']) {
            return null;
        }

        $shortSha = self::escapeSlackMrkdwn($commit['short_sha']);
        $line = '*Commit:* '.($commit['url']
            ? '<'.self::escapeSlackLink($commit['url']).'|'.$shortSha.'>'
            : $shortSha);

        if ($commit['branch']) {
            $branch = str_replace(["\r\n", "\r", "\n"], ' ', $commit['branch']);
            $line .= ' on '.self::escapeSlackMrkdwn($branch);
        }

        return $line;
    }

    private static function escapeSlackMrkdwn(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }

    private static function escapeSlackLink(string $url): string
    {
        return str_replace(['&', '<', '>', '|'], ['&amp;', '&lt;', '&gt;', '%7C'], $url);
    }

    /** Escape Markdown in user-controlled text; Blade escapes the HTML. */
    private static function escapeMailMarkdown(string $text): string
    {
        return preg_replace('/([\\\\`*_{}\[\]()#+\-.!|~=])/u', '\\\\$1', $text);
    }

    private static function formatCommitMessageForMail(string $message): string
    {
        $paragraphs = preg_split('/\R\s*\R/u', str_replace("\r\n", "\n", $message));

        return collect($paragraphs)
            ->map(fn (string $paragraph): string => collect(preg_split('/\R/u', $paragraph))
                ->map(fn (string $line): string => self::escapeMailMarkdown(trim($line)))
                ->filter(fn (string $line): bool => $line !== '')
                ->implode("\\\n"))
            ->filter(fn (string $paragraph): bool => $paragraph !== '')
            ->implode("\n\n");
    }
}
