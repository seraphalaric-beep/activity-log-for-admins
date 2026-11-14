import { fileURLToPath } from "node:url";
import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";

export default defineConfig({
  plugins: [react(), tailwindcss()],
  define: { "process.env.NODE_ENV": JSON.stringify("production") },
  build: {
    outDir: fileURLToPath(new URL("./.tmp/wp-assets-preview", import.meta.url)),
    emptyOutDir: true,
    lib: {
      entry: "ui/main.jsx",
      name: "ActivityLogForAdminsPreview",
      formats: ["iife"],
      fileName: () => "admin.js",
      cssFileName: "admin",
    },
  },
});
