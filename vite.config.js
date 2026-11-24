import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
export default defineConfig({
  publicDir: 'public_html',
  plugins: [react()],
  build: { outDir: 'dist', emptyOutDir: true },
});