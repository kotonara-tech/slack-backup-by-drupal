"use client";

/**
 * facet サイドバー（presentational、M5）。フェッチしない。
 *
 * `meta.facets` は `empty_behavior: none` のため 0 件時に facet 自体が配列から
 * 省略される。その場合でも、現在の `filters`（state）に絞り込み値が残っていれば
 * 「適用中フィルタ」chip として表示し、× で解除できるようにする。
 */
import { Badge, CloseButton, Group, NavLink, Stack, Text } from "@mantine/core";

import type { Facet, SearchFilters } from "@/lib/types/search";

type FacetId = "channel" | "slack_user" | "posted_at";

interface FacetSidebarProps {
  facets: Facet[];
  filters: SearchFilters;
  onToggle: (facetId: FacetId, value: string) => void;
}

/** facetId ⇔ SearchFilters のキー対応（lib/search-api.ts の対応と一致させる）。 */
const FILTER_KEY_BY_FACET: Record<FacetId, "channel" | "slackUser" | "postedAt"> = {
  channel: "channel",
  slack_user: "slackUser",
  posted_at: "postedAt",
};

/**
 * 適用中フィルタ chip の aria-label 用の表示ラベル。
 * chip は facets 配列に facet 自体が無い（0 件省略）ときにも出るため、
 * `facet.label` に依存できず静的な対応表を持つ。
 */
const FACET_DISPLAY_LABEL: Record<FacetId, string> = {
  channel: "Channel",
  slack_user: "Slack User",
  posted_at: "Posted At",
};

const FACET_IDS = Object.keys(FILTER_KEY_BY_FACET) as FacetId[];

interface ActiveChip {
  facetId: FacetId;
  value: string;
}

/** facets に無い（0 件省略）が state には値がある facet を chip 対象として集める。 */
function collectStateOnlyActiveChips(
  facets: Facet[],
  filters: SearchFilters,
): ActiveChip[] {
  return FACET_IDS.flatMap((facetId) => {
    const value = filters[FILTER_KEY_BY_FACET[facetId]];
    const hasFacet = facets.some((f) => f.id === facetId);
    return value && !hasFacet ? [{ facetId, value }] : [];
  });
}

export function FacetSidebar({ facets, filters, onToggle }: FacetSidebarProps) {
  const activeChips = collectStateOnlyActiveChips(facets, filters);

  if (facets.length === 0 && activeChips.length === 0) {
    return null;
  }

  return (
    <Stack gap="md" data-testid="facet-sidebar">
      {activeChips.length > 0 && (
        <Group gap="xs" data-testid="active-filters">
          {activeChips.map(({ facetId, value }) => (
            <Badge
              key={facetId}
              variant="light"
              data-testid={`active-filter-${facetId}`}
              rightSection={
                <CloseButton
                  size="xs"
                  aria-label={`${FACET_DISPLAY_LABEL[facetId]}: ${value} の絞り込みを解除`}
                  onClick={() => onToggle(facetId, value)}
                />
              }
            >
              {value}
            </Badge>
          ))}
        </Group>
      )}

      {facets.map((facet) => (
        <Stack key={facet.id} gap={2}>
          <Text size="sm" fw={600}>
            {facet.label}
          </Text>
          {facet.terms.map((term) => (
            <NavLink
              key={term.value}
              component="button"
              data-testid={`facet-term-${facet.id}-${term.value}`}
              label={`${term.value} (${term.count})`}
              active={term.active}
              aria-current={term.active ? "true" : undefined}
              onClick={() => onToggle(facet.id as FacetId, term.value)}
            />
          ))}
        </Stack>
      ))}
    </Stack>
  );
}
