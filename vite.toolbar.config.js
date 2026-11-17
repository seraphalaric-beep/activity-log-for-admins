import { fileURLToPath } from "node:url";
import { defineConfig } from "vite";

const sourceArchive = new URL(".", import.meta.url).pathname.endsWith("/source/");

export default defineConfig({
  build: {
    outDir: fileURLToPath(new URL(sourceArchive ? "../build" : "./activity-log-for-admins/build", import.meta.url)),
    emptyOutDir: false,
    lib: { entry: "ui/toolbar.js", name: "ActivityLogToolbar", formats: ["iife"], fileName: () => "toolbar.js", cssFileName: "toolbar" },
  },
});
