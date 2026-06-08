import { render } from "@testing-library/react";
import { MantineProvider } from "@mantine/core";
import type { ReactNode } from "react";

/** Mantine コンポーネントを MantineProvider で包んで render する共通ヘルパ。 */
export function renderWithMantine(ui: ReactNode) {
  return render(<MantineProvider>{ui}</MantineProvider>);
}
