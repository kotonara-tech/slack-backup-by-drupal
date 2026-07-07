import { describe, expect, it, vi } from "vitest";
import { fireEvent, screen } from "@testing-library/react";

import { SearchBar } from "@/components/SearchBar";
import { renderWithMantine as renderUI } from "../helpers/render";

/**
 * small (Vitest): SearchBar は submit 型（Enter／ボタンで確定、入力毎ではない）。
 */
describe("SearchBar", () => {
  it("入力しただけでは onSearch を呼ばない", () => {
    const onSearch = vi.fn();
    renderUI(<SearchBar onSearch={onSearch} />);

    fireEvent.change(screen.getByRole("searchbox"), {
      target: { value: "hello" },
    });

    expect(onSearch).not.toHaveBeenCalled();
  });

  it("フォーム送信（Enter 相当）で onSearch(value) を呼ぶ", () => {
    const onSearch = vi.fn();
    renderUI(<SearchBar onSearch={onSearch} />);

    fireEvent.change(screen.getByRole("searchbox"), {
      target: { value: "hello" },
    });
    fireEvent.submit(screen.getByRole("search"));

    expect(onSearch).toHaveBeenCalledWith("hello");
  });

  it("検索ボタンのクリックでも onSearch(value) を呼ぶ", () => {
    const onSearch = vi.fn();
    renderUI(<SearchBar onSearch={onSearch} />);

    fireEvent.change(screen.getByRole("searchbox"), {
      target: { value: "world" },
    });
    fireEvent.click(screen.getByRole("button", { name: "検索" }));

    expect(onSearch).toHaveBeenCalledWith("world");
  });

  it("クリアボタン（×）で入力を空にし onSearch(\"\") を呼ぶ", () => {
    const onSearch = vi.fn();
    renderUI(<SearchBar onSearch={onSearch} initialValue="hello" />);

    fireEvent.click(screen.getByRole("button", { name: "検索をクリア" }));

    expect(onSearch).toHaveBeenCalledWith("");
    expect(screen.getByRole("searchbox")).toHaveValue("");
  });

  it("initialValue を入力の初期値として使う", () => {
    renderUI(<SearchBar onSearch={vi.fn()} initialValue="foo" />);

    expect(screen.getByRole("searchbox")).toHaveValue("foo");
  });

  it("入力が空のときはクリアボタンを表示しない", () => {
    renderUI(<SearchBar onSearch={vi.fn()} />);

    expect(
      screen.queryByRole("button", { name: "検索をクリア" }),
    ).not.toBeInTheDocument();
  });
});
