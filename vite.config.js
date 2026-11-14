import { fileURLToPath } from "node:url";
import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";

const sourceArchive = new URL(".", import.meta.url).pathname.endsWith("/source/");

export default defineConfig({
  plugins: [react(), tailwindcss()],
  publicDir: fileURLToPath(new URL("./ui/public", import.meta.url)),
  define: { "process.env.NODE_ENV": JSON.stringify("production") },
  build: {
    outDir: fileURLToPath(new URL(sourceArchive ? "../build" : "./activity-log-for-admins/build", import.meta.url)),
    emptyOutDir: true,
    lib: { entry: "ui/main.jsx", name: "ActivityLogForAdmins", formats: ["iife"], fileName: () => "admin.js", cssFileName: "admin" },
    rollupOptions: {
      external: id => id === "react" || id.startsWith("react/") || id === "react-dom" || id.startsWith("react-dom/"),
      output: {
        globals: {
          react: "React",
          "react-dom": "ReactDOM",
          "react-dom/client": "ReactDOM",
          "react/jsx-runtime": "ReactJSXRuntime",
          "react/jsx-dev-runtime": "ReactJSXRuntime",
        },
      },
    },
  },
});
