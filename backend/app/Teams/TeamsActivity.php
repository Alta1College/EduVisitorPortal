<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Teams;

/**
 * One Teams activity-feed alert. Contains no visitor data beyond a display name and the site
 * name: the text is shown on the user's devices and stored by Microsoft.
 *
 * $chat is the same notification as a chat message from the app's bot (more detail: company,
 * visitor type, times), used only when TEAMS_DELIVERY=chat.
 */
final class TeamsActivity
{
    public const VISITOR_ARRIVED = 'visitorArrived';

    public const VISIT_CREATED = 'visitCreated';

    public const VISIT_REMINDER = 'visitReminder';

    /**
     * English template text per activity type, used in the app manifest. The parameters must
     * match the templateParameters each notification sends.
     *
     * @return array<string, array{description: string, templateText: string}>
     */
    public static function types(): array
    {
        return [
            self::VISITOR_ARRIVED => [
                'description' => 'A visitor has arrived',
                'templateText' => '{visitor} has arrived at {site}',
            ],
            self::VISIT_CREATED => [
                'description' => 'A visit was booked for you',
                'templateText' => 'New visit booked for you: {visitor} at {site}',
            ],
            self::VISIT_REMINDER => [
                'description' => 'Daily reminder of your visits',
                'templateText' => 'Visits today: {count}',
            ],
        ];
    }

    /**
     * @param  array<string, string>  $templateParameters  name => value
     */
    public function __construct(
        public readonly string $activityType,
        public readonly string $title,
        public readonly string $previewText,
        public readonly array $templateParameters = [],
        public readonly ?int $visitId = null,
        public readonly ?TeamsChatMessage $chat = null,
    ) {}
}
