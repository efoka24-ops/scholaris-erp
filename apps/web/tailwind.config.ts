import type { Config } from "tailwindcss";
import { scholarisTailwindPreset } from "@scholaris/ui";

const config: Config = {
  darkMode: ["class"],
  content: ["./src/**/*.{ts,tsx}"],
  presets: [scholarisTailwindPreset as Config],
};

export default config;
