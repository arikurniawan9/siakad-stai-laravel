import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import type { ComponentType } from 'react';

const pages = import.meta.glob<{ default: ComponentType }>('./pages/**/*.tsx');

createInertiaApp({
  title: (title) => `${title} · SIAKAD`,
  resolve: async (name) => {
    const loader = pages[`./pages/${name}.tsx`];
    if (!loader) throw new Error(`Halaman Inertia tidak ditemukan: ${name}`);
    return (await loader()).default;
  },
  setup({ el, App, props }) {
    if (el) createRoot(el).render(<App {...props} />);
  },
  progress: { color: '#39d0b4', showSpinner: false },
});
