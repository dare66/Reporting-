import { useEffect, useState } from "react";

type Theme = "light" | "dark" | "system";

function apply(t: Theme) {
  const dark = t === "dark" || (t === "system" && window.matchMedia("(prefers-color-scheme: dark)").matches);
  document.documentElement.classList.toggle("dark", dark);
}

export function useTheme() {
  const [theme, setTheme] = useState<Theme>(() => {
    try { return (localStorage.getItem("netops-theme") as Theme) || "system"; } catch { return "system"; }
  });
  useEffect(() => {
    apply(theme);
    try { localStorage.setItem("netops-theme", theme); } catch { /* storage unavailable */ }
    const mq = window.matchMedia("(prefers-color-scheme: dark)");
    const on = () => theme === "system" && apply("system");
    mq.addEventListener("change", on);
    return () => mq.removeEventListener("change", on);
  }, [theme]);
  const cycle = () => setTheme(t => (t === "light" ? "dark" : t === "dark" ? "system" : "light"));
  return { theme, setTheme, cycle };
}
