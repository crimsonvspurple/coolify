<div>
    <x-slot:title>
        Slack Notifications | Coolify
    </x-slot>

    <x-notification.settings-layout>
    <div class="application-settings-form flex flex-col gap-6">
        <form wire:submit="submit">
            <x-unsaved-bar action="submit" />
            <x-application.settings-section title="Slack"
                description="Send team notifications to Slack through an incoming webhook.">
                <x-slot:actions>
                    <x-notification.channel-actions :enabled="$slackEnabled" enabledProperty="slackEnabled"
                        toggleMethod="instantSaveSlackEnabled" :canUpdate="auth()->user()->can('update', $settings)"
                        :canResource="$settings" />
                </x-slot:actions>

                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="lg:col-span-2">
                        @can('update', $settings)
                            <x-forms.input type="password" :required="$slackEnabled" id="slackWebhookUrl" label="Webhook URL"
                                helper="Create an incoming webhook in your Slack app settings." />
                        @else
                            <x-forms.input disabled label="Webhook URL" value="Hidden (only admins can view)" />
                        @endcan
                    </div>
                </div>
            </x-application.settings-section>
        </form>

        <x-notification.event-grid :settings="$settings" channel="slack">
            <x-forms.checkbox canGate="update" :canResource="$settings" instantSave="instantSaveDeploymentCommitDetails"
                id="deploymentCommitDetailsSlackNotifications"
                label="Include commit details in deployment Slack notifications"
                helper="Adds the commit SHA, branch, and commit message to deployment success and failure Slack notifications." />
        </x-notification.event-grid>
    </div>
    </x-notification.settings-layout>
</div>
