import { describe, expect, it, vi } from "vitest";
import { fireEvent, screen } from "@testing-library/react";

import { FacetSidebar } from "@/components/FacetSidebar";
import type { Facet } from "@/lib/types/search";
import { renderWithMantine as renderUI } from "../helpers/render";

const facets: Facet[] = [
  {
    id: "channel",
    label: "Channel",
    terms: [
      { value: "general", count: 42, active: false },
      { value: "random", count: 7, active: true },
    ],
  },
  {
    id: "slack_user",
    label: "Slack User",
    terms: [{ value: "taro", count: 3, active: false }],
  },
];

/**
 * small (Vitest): FacetSidebar は純表示（フェッチしない）。
 * facet 一覧表示・クリックでの onToggle・active 強調・state 起点の適用中フィルタ chip
 * （0 件省略で facets に無くても表示）を検証する。
 */
describe("FacetSidebar", () => {
  it("facet ラベルと値(件数)を一覧表示する", () => {
    renderUI(
      <FacetSidebar
        facets={facets}
        filters={{ fulltext: "hello", offset: 0 }}
        onToggle={vi.fn()}
      />,
    );

    expect(screen.getByText("Channel")).toBeInTheDocument();
    expect(screen.getByText("general (42)")).toBeInTheDocument();
    expect(screen.getByText("random (7)")).toBeInTheDocument();
    expect(screen.getByText("Slack User")).toBeInTheDocument();
    expect(screen.getByText("taro (3)")).toBeInTheDocument();
  });

  it("値をクリックすると facetId と value で onToggle する", () => {
    const onToggle = vi.fn();
    renderUI(
      <FacetSidebar
        facets={facets}
        filters={{ fulltext: "hello", offset: 0 }}
        onToggle={onToggle}
      />,
    );

    fireEvent.click(screen.getByText("general (42)"));

    expect(onToggle).toHaveBeenCalledWith("channel", "general");
  });

  it("active な値を aria-current で強調する", () => {
    renderUI(
      <FacetSidebar
        facets={facets}
        filters={{ fulltext: "hello", channel: "random", offset: 0 }}
        onToggle={vi.fn()}
      />,
    );

    expect(screen.getByTestId("facet-term-channel-random")).toHaveAttribute(
      "aria-current",
      "true",
    );
  });

  it("facets から省略（0件）されていても state に値があれば適用中フィルタ chip を出す", () => {
    const onToggle = vi.fn();
    renderUI(
      <FacetSidebar
        facets={[]}
        filters={{ fulltext: "hello", channel: "general", offset: 0 }}
        onToggle={onToggle}
      />,
    );

    const chip = screen.getByTestId("active-filter-channel");
    expect(chip).toHaveTextContent("general");

    fireEvent.click(screen.getByLabelText("channel の絞り込みを解除"));
    expect(onToggle).toHaveBeenCalledWith("channel", "general");
  });

  it("facet も適用中フィルタも無ければ何も描画しない", () => {
    renderUI(
      <FacetSidebar
        facets={[]}
        filters={{ fulltext: "hello", offset: 0 }}
        onToggle={vi.fn()}
      />,
    );

    expect(screen.queryByTestId("facet-sidebar")).not.toBeInTheDocument();
  });
});
