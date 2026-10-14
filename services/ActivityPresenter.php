<?php

namespace YesWiki\Discourse\Service;

use YesWiki\Render\Service\TemplateEngine;

/** Draws a topic's activity as a dozen weekly bars of inline SVG, in the colour of the text around it. */
class ActivityPresenter
{
    public const WEEKS = 12;
    private const WIDTH = 4;
    private const GAP = 2;
    private const HEIGHT = 18;

    public function __construct(private readonly TemplateEngine $templateEngine)
    {
    }

    /**
     * The number of replies and the sparkline, as one inline block for an entry or a card.
     *
     * @param array<string, mixed> $activity as TopicActivity::get() gives it
     */
    public function fragment(array $activity): string
    {
        return $this->templateEngine->render('@discourse/discourse-activity.twig', [
            'replies' => (int)($activity['replies'] ?? 0),
            'sparkline' => $this->sparkline($activity),
        ]);
    }

    /**
     * Posts per rolling week, oldest first; the last one ends now.
     *
     * @param array<int, int> $postsByDaysAgo
     *
     * @return list<int>
     */
    public function weeks(array $postsByDaysAgo, int $weeks = self::WEEKS): array
    {
        $buckets = array_fill(0, $weeks, 0);
        foreach ($postsByDaysAgo as $daysAgo => $count) {
            $week = intdiv(max(0, (int)$daysAgo), 7);
            if ($week < $weeks) {
                $buckets[$weeks - 1 - $week] += (int)$count;
            }
        }

        return array_values($buckets);
    }

    /** @param array<string, mixed> $activity as TopicActivity::get() gives it */
    public function sparkline(array $activity, int $weeks = self::WEEKS): string
    {
        $buckets = $this->weeks(is_array($activity['postsByDaysAgo'] ?? null) ? $activity['postsByDaysAgo'] : [], max(1, $weeks));
        $weeks = count($buckets);
        $peak = $buckets === [] ? 0 : max($buckets);
        $now = (int)($activity['fetchedAt'] ?? time());
        $format = _t('DISCOURSE_ACTIVITY_DATE_FORMAT');
        $width = $weeks * (self::WIDTH + self::GAP) - self::GAP;
        $bars = '';

        foreach ($buckets as $index => $posts) {
            $height = $peak > 0 && $posts > 0 ? max(2, (int)round($posts / $peak * self::HEIGHT)) : 1;
            $weeksAgo = $weeks - 1 - $index;
            $tip = _t('DISCOURSE_ACTIVITY_WEEK', [
                'from' => date($format, $now - ($weeksAgo * 7 + 6) * 86400),
                'to' => date($format, $now - $weeksAgo * 7 * 86400),
                'posts' => $posts,
            ]);
            $opacity = $posts === 0 ? '0.2' : ($weeksAgo === 0 ? '1' : '0.55');
            $bars .= '<rect x="' . $index * (self::WIDTH + self::GAP) . '" y="' . (self::HEIGHT - $height) . '"'
                . ' width="' . self::WIDTH . '" height="' . $height . '" rx="1" opacity="' . $opacity . '">'
                . '<title>' . htmlspecialchars($tip) . '</title></rect>';
        }

        $label = _t('DISCOURSE_ACTIVITY_SPARKLINE_LABEL', ['posts' => array_sum($buckets), 'weeks' => $weeks, 'last' => end($buckets)]);

        return '<svg class="discourse-sparkline" role="img" aria-label="' . htmlspecialchars($label) . '"'
            . ' width="' . $width . '" height="' . self::HEIGHT . '" viewBox="0 0 ' . $width . ' ' . self::HEIGHT . '"'
            . ' fill="currentColor"><title>' . htmlspecialchars($label) . '</title>' . $bars . '</svg>';
    }
}
