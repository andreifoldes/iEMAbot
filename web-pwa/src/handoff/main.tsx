import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { HandoffApp } from './HandoffApp';
import { initTelegram } from '../lib/telegramWebApp';
import '../index.css';

// Single-item page for chat channels. Deliberately no service worker, install prompt or
// study state: it only answers the one item named by the link's token.
initTelegram();

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <HandoffApp token={new URLSearchParams(window.location.search).get('t') ?? ''} />
  </StrictMode>,
);
