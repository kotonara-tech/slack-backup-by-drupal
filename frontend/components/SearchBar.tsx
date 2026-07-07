"use client";

/**
 * 全文検索の検索バー（M5）。
 *
 * submit 型（Enter またはボタンで `onSearch(value)` を確定発火する）。
 * 入力のたびにフェッチしないよう、内部 state に保持し送信時のみ通知する。
 * クリアボタン（×）は入力が空でないときのみ表示し、押すと即 `onSearch("")`。
 */
import { useState, type FormEvent } from "react";
import { ActionIcon, Button, Group, TextInput } from "@mantine/core";

interface SearchBarProps {
  /** 入力の初期値（URL 状態からの復元など）。 */
  initialValue?: string;
  onSearch: (value: string) => void;
}

export function SearchBar({ initialValue = "", onSearch }: SearchBarProps) {
  const [value, setValue] = useState(initialValue);

  const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    onSearch(value.trim());
  };

  const handleClear = () => {
    setValue("");
    onSearch("");
  };

  return (
    <form role="search" onSubmit={handleSubmit}>
      <Group gap="xs" align="flex-end">
        <TextInput
          type="search"
          aria-label="メッセージを検索"
          placeholder="メッセージを検索"
          value={value}
          onChange={(event) => setValue(event.currentTarget.value)}
          rightSection={
            value ? (
              <ActionIcon
                aria-label="検索をクリア"
                variant="subtle"
                color="gray"
                onClick={handleClear}
              >
                ×
              </ActionIcon>
            ) : null
          }
          style={{ flex: 1 }}
        />
        <Button type="submit">検索</Button>
      </Group>
    </form>
  );
}
