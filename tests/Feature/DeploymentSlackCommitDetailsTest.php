<?php

use App\Livewire\Notifications\Slack as NotificationSlack;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Application\DeploymentFailed;
use App\Notifications\Application\DeploymentSuccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const SLACK_DEPLOYMENT_COMMIT_SHA = '3f9c2a7b1d4e5f60718293a4b5c6d7e8f9012345';

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::factory()->create([
        'server_id' => $server->id,
        'network' => 'slack-commit-details-'.fake()->unique()->word(),
    ]);
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'git_repository' => 'https://github.com/acme/shop.git',
        'git_branch' => 'main',
        'fqdn' => 'https://shop.example.com',
    ]);
});

function createSlackDeploymentRow(Application $application, array $attributes = []): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'deployment_uuid' => 'deploy-'.fake()->unique()->uuid(),
        'commit' => SLACK_DEPLOYMENT_COMMIT_SHA,
        'commit_message' => "Add checkout page\n\nHandles card and wallet payments.",
        'status' => 'finished',
        ...$attributes,
    ]);
}

function enableSlackCommitDetails(Team $team, bool $enabled = true): void
{
    $team->slackNotificationSettings->update(['deployment_commit_details_slack_notifications' => $enabled]);
    $team->refresh();
}

function slackDeploymentDescription(string $notificationClass, Application $application, ApplicationDeploymentQueue $deployment, ?ApplicationPreview $preview = null): string
{
    return (new $notificationClass($application, $deployment->deployment_uuid, $preview, $deployment))->toSlack()->description;
}

it('defaults the slack commit details setting to off', function () {
    expect($this->team->slackNotificationSettings->fresh()->deployment_commit_details_slack_notifications)->toBeFalse();
});

it('includes commit details in deployment slack notifications when enabled', function (string $notificationClass) {
    enableSlackCommitDetails($this->team);
    $deployment = createSlackDeploymentRow($this->application);

    $description = slackDeploymentDescription($notificationClass, $this->application, $deployment);

    $commitAt = strpos($description, '*Commit:*');
    $logsAt = strrpos($description, 'Deployment Logs');

    expect($description)
        ->toContain('<https://github.com/acme/shop/commit/'.SLACK_DEPLOYMENT_COMMIT_SHA.'|3f9c2a7> on main')
        ->toContain('Add checkout page')
        ->toContain('Handles card and wallet payments.')
        ->toContain('Deployment Logs')
        ->and($commitAt)->toBeInt()
        ->and($logsAt)->toBeGreaterThan($commitAt);
})->with([DeploymentSuccess::class, DeploymentFailed::class]);

it('leaves deployment slack notifications unchanged when the setting is off', function (string $notificationClass) {
    enableSlackCommitDetails($this->team, false);
    $deployment = createSlackDeploymentRow($this->application);

    $description = slackDeploymentDescription($notificationClass, $this->application, $deployment);

    expect($description)
        ->not->toContain('3f9c2a7')
        ->not->toContain('Add checkout page')
        ->not->toContain('*Commit:*')
        ->toContain('Deployment Logs');
})->with([DeploymentSuccess::class, DeploymentFailed::class]);

it('hides the commit block when the deployment has no commit information', function () {
    enableSlackCommitDetails($this->team);
    $deployment = createSlackDeploymentRow($this->application, ['commit' => 'HEAD', 'commit_message' => null]);

    $description = slackDeploymentDescription(DeploymentSuccess::class, $this->application, $deployment);

    expect($description)
        ->not->toContain('*Commit:*')
        ->not->toContain('*Commit message:*')
        ->toContain('Deployment Logs');
});

it('shows a commit message without a link when the sha is not a commit', function () {
    enableSlackCommitDetails($this->team);
    $deployment = createSlackDeploymentRow($this->application, ['commit' => 'HEAD']);

    $description = slackDeploymentDescription(DeploymentSuccess::class, $this->application, $deployment);

    expect($description)
        ->not->toContain('*Commit:*')
        ->toContain('*Commit message:*')
        ->toContain('Add checkout page')
        ->toContain('Deployment Logs');
});

it('shows the commit link without a message when the deployment has no message', function () {
    enableSlackCommitDetails($this->team);
    $deployment = createSlackDeploymentRow($this->application, ['commit_message' => null]);

    $description = slackDeploymentDescription(DeploymentSuccess::class, $this->application, $deployment);

    expect($description)
        ->toContain('<https://github.com/acme/shop/commit/'.SLACK_DEPLOYMENT_COMMIT_SHA.'|3f9c2a7> on main')
        ->not->toContain('*Commit message:*')
        ->toContain('Deployment Logs');
});

it('shows the commit for preview deployments without the base branch', function () {
    enableSlackCommitDetails($this->team);
    $preview = ApplicationPreview::create([
        'application_id' => $this->application->id,
        'pull_request_id' => 42,
        'pull_request_html_url' => 'https://github.com/acme/shop/pull/42',
        'fqdn' => 'https://pr-42.shop.example.com',
    ]);
    $deployment = createSlackDeploymentRow($this->application, ['pull_request_id' => 42]);

    $message = (new DeploymentSuccess($this->application, $deployment->deployment_uuid, $preview, $deployment))->toSlack();

    expect($message->title)->toContain('Pull request #42')
        ->and($message->description)
        ->toContain('3f9c2a7')
        ->toContain('Add checkout page')
        ->not->toContain(' on main');
});

it('escapes slack link characters in the commit url', function () {
    enableSlackCommitDetails($this->team);
    $this->application->update([
        'git_repository' => 'https://git.example.com/acme/shop.git?foo=1&bar=2',
    ]);
    $this->application->refresh();
    $deployment = createSlackDeploymentRow($this->application);

    $description = slackDeploymentDescription(DeploymentSuccess::class, $this->application, $deployment);
    $url = $this->application->gitCommitLink(SLACK_DEPLOYMENT_COMMIT_SHA);
    $escapedUrl = str_replace(['&', '<', '>', '|'], ['&amp;', '&lt;', '&gt;', '%7C'], (string) $url);

    expect($url)->toContain('&')
        ->and($description)->toContain('<'.$escapedUrl.'|')
        ->and($description)->not->toContain($url);
});

it('keeps a multiline branch on the commit line', function () {
    enableSlackCommitDetails($this->team);
    $this->application->update(['git_branch' => "release/one\nsecond"]);
    $this->application->refresh();
    $deployment = createSlackDeploymentRow($this->application);

    $description = slackDeploymentDescription(DeploymentSuccess::class, $this->application, $deployment);

    expect($description)
        ->toContain('on release/one second')
        ->not->toContain("on release/one\nsecond");
});

it('shortens the commit message so the slack section stays within 3000 characters', function () {
    enableSlackCommitDetails($this->team);
    $this->application->name = str_repeat('N', 2200);
    $deployment = createSlackDeploymentRow($this->application, [
        'commit_message' => str_repeat('M', 1000),
    ]);

    $description = slackDeploymentDescription(DeploymentSuccess::class, $this->application, $deployment);

    expect(mb_strlen($description))->toBeLessThanOrEqual(3000)
        ->and($description)->toContain('*Commit:*')
        ->and($description)->toContain('...')
        ->and($description)->not->toContain(str_repeat('M', 1000))
        ->and(substr_count($description, '```'))->toBe(2)
        ->and(str_ends_with($description, 'Deployment Logs>*'))->toBeTrue();
});

it('keeps the deployment logs link when the slack text is already over the limit', function () {
    enableSlackCommitDetails($this->team);
    $this->application->name = str_repeat('N', 4000);
    $deployment = createSlackDeploymentRow($this->application, [
        'commit_message' => str_repeat('M', 1000),
    ]);

    $description = slackDeploymentDescription(DeploymentSuccess::class, $this->application, $deployment);

    expect(mb_strlen($description))->toBeLessThanOrEqual(3000)
        ->and($description)->not->toContain(str_repeat('N', 4000))
        ->and($description)->not->toContain('*Commit:*')
        ->and(str_ends_with($description, 'Deployment Logs>*'))->toBeTrue();
});

it('does not put repository credentials in the slack commit link', function () {
    enableSlackCommitDetails($this->team);
    $this->application->update([
        'git_repository' => 'https://ci-user:secret-token@git.example.com/acme/shop.git',
    ]);
    $this->application->refresh();
    $deployment = createSlackDeploymentRow($this->application);

    $description = slackDeploymentDescription(DeploymentSuccess::class, $this->application, $deployment);

    expect($description)
        ->toContain('<https://git.example.com/acme/shop/commit/'.SLACK_DEPLOYMENT_COMMIT_SHA.'|3f9c2a7>')
        ->not->toContain('secret-token')
        ->not->toContain('ci-user');
});

it('escapes slack mrkdwn in the commit line and keeps the message inside one code fence', function () {
    enableSlackCommitDetails($this->team);
    $this->application->update(['git_branch' => 'release/<v1>&']);
    $deployment = createSlackDeploymentRow($this->application, [
        'commit_message' => "Fix <script>\n```\necho hi\n```",
    ]);

    $description = slackDeploymentDescription(DeploymentSuccess::class, $this->application, $deployment);

    expect($description)
        ->toContain('on release/&lt;v1&gt;&amp;')
        ->not->toContain('on release/<v1>&')
        ->toContain("'''")
        ->toContain('echo hi')
        ->toContain('Fix <script>')
        ->and(substr_count($description, '```'))->toBe(2);

    $logsAt = strrpos($description, 'Deployment Logs');
    $fenceEnd = strrpos($description, '```');
    expect($logsAt)->toBeGreaterThan($fenceEnd);
});

it('appends nothing when the team has no slack notification settings', function () {
    $this->team->slackNotificationSettings()->delete();
    $deployment = createSlackDeploymentRow($this->application);

    $description = slackDeploymentDescription(DeploymentSuccess::class, $this->application, $deployment);

    expect($description)
        ->not->toContain('*Commit:*')
        ->not->toContain('Add checkout page')
        ->toContain('Deployment Logs');
});

it('lets team admins toggle commit details from slack notification settings', function () {
    $admin = User::factory()->create();
    $this->team->members()->attach($admin->id, ['role' => 'admin']);
    $this->actingAs($admin);
    session(['currentTeam' => $this->team]);
    Cache::flush();

    $html = Livewire::test(NotificationSlack::class)
        ->assertSet('deploymentCommitDetailsSlackNotifications', false)
        ->assertSee('Include commit details in deployment Slack notifications')
        ->set('deploymentCommitDetailsSlackNotifications', true)
        ->call('instantSaveDeploymentCommitDetails')
        ->assertHasNoErrors()
        ->html();

    expect($html)->toMatch('/data-settings-section-title="Notification events"[\s\S]*?deploymentCommitDetailsSlackNotifications[\s\S]*?<\/section>/');

    expect($this->team->slackNotificationSettings->fresh()->deployment_commit_details_slack_notifications)->toBeTrue();
});

it('does not let team members change the slack commit details setting', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);
    Cache::flush();

    Livewire::test(NotificationSlack::class)
        ->set('deploymentCommitDetailsSlackNotifications', true)
        ->call('instantSaveDeploymentCommitDetails')
        ->assertSet('deploymentCommitDetailsSlackNotifications', false);

    expect($this->team->slackNotificationSettings->fresh()->deployment_commit_details_slack_notifications)->toBeFalse();
});

it('does not let admins of another team change the slack commit details setting', function () {
    $otherTeam = Team::factory()->create();
    $outsider = User::factory()->create();
    $otherTeam->members()->attach($outsider->id, ['role' => 'admin']);
    $this->actingAs($outsider);
    session(['currentTeam' => $otherTeam]);
    Cache::flush();

    Livewire::test(NotificationSlack::class)
        ->set('deploymentCommitDetailsSlackNotifications', true)
        ->call('instantSaveDeploymentCommitDetails');

    expect($this->team->slackNotificationSettings->fresh()->deployment_commit_details_slack_notifications)->toBeFalse()
        ->and($otherTeam->slackNotificationSettings->fresh()->deployment_commit_details_slack_notifications)->toBeTrue();
});
