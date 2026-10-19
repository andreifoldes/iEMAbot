/**
 * Chat-channel web handoff: renders ONE questionnaire item that a chat (Telegram) cannot
 * show — a visual analogue scale, the keystroke-logging writer or an m2c2kit cognitive
 * task — with the PWA's own components, posts the answer back
 * (api/channel_handoff.php) and returns the participant to the chat.
 */
import { useEffect, useMemo, useState } from 'react';
import { SurveyInputs } from '../components/SurveyInputs';
import { KeystrokeRecorder } from '../components/KeystrokeRecorder';
import { adaptQuestionnaire } from '../lib/esmiraAdapter';
import { fetchHandoff, submitHandoff, type HandoffItem, type HandoffResult } from '../lib/handoffApi';
import {
  cognitiveSummaryValue, cognitiveTrialRows, newCognitiveSession, type M2c2Complete,
} from '../lib/cognitiveTrials';
import { closeTelegram, isInTelegram } from '../lib/telegramWebApp';
import type { EsmiraQuestionnaire, PreloadedQuestion } from '../types';

type Phase = 'loading' | 'question' | 'sending' | 'done' | 'error';

export function HandoffApp({ token }: { token: string }) {
  const [phase, setPhase] = useState<Phase>('loading');
  const [error, setError] = useState('');
  const [item, setItem] = useState<HandoffItem | null>(null);
  const [webview, setWebview] = useState<{ url: string; title: string } | null>(null);
  const [writing, setWriting] = useState(false);
  const reduceMotion = useMemo(() => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false, []);

  useEffect(() => {
    if (!token) {
      setError('This link is incomplete.');
      setPhase('error');
      return;
    }
    fetchHandoff(token)
      .then((it) => { setItem(it); setPhase('question'); })
      .catch((e: Error) => { setError(e.message); setPhase('error'); });
  }, [token]);

  const question: PreloadedQuestion | null = useMemo(() => {
    if (!item) return null;
    const q = { internalId: 0, title: item.questionnaireName, pages: [{ inputs: [item.input] }] } as EsmiraQuestionnaire;
    return adaptQuestionnaire(item.studyId, q, Date.now()).questions[0] ?? null;
  }, [item]);

  const send = async (result: HandoffResult) => {
    setPhase('sending');
    try {
      await submitHandoff(token, result);
      setPhase('done');
      // Give the participant a moment to see the confirmation, then return to the chat.
      window.setTimeout(() => closeTelegram(), 900);
    } catch (e) {
      setError((e as Error).message);
      setPhase('error');
    }
  };

  // m2c2kit tasks post their result from the iframe (embed mode), as in the PWA survey.
  useEffect(() => {
    if (!webview || !question) return;
    let origin: string;
    try { origin = new URL(webview.url, window.location.href).origin; } catch { return; }
    const handler = (e: MessageEvent) => {
      if (e.origin !== origin) return;
      const d = e.data as M2c2Complete | undefined;
      if (!d || d.type !== 'm2c2:complete') return;
      const session = newCognitiveSession();
      setWebview(null);
      void send({
        value: cognitiveSummaryValue(d, session),
        trials: cognitiveTrialRows(d, item?.questionnaireName ?? '', question.id, session),
      });
    };
    window.addEventListener('message', handler);
    return () => window.removeEventListener('message', handler);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [webview, question]);

  if (phase === 'loading' || phase === 'sending') {
    return <Centered><p className="text-on-surface-variant">{phase === 'loading' ? 'Loading…' : 'Saving…'}</p></Centered>;
  }
  if (phase === 'error' || !question) {
    return (
      <Centered>
        <p className="font-semibold text-on-surface">{error || 'This question is not available.'}</p>
        <p className="text-sm text-on-surface-variant mt-2">You can close this page and continue in the chat.</p>
      </Centered>
    );
  }
  if (phase === 'done') {
    return (
      <Centered>
        <p className="text-2xl" aria-hidden="true">✅</p>
        <p className="font-semibold text-on-surface mt-2">Thank you!</p>
        <p className="text-sm text-on-surface-variant mt-1">
          {isInTelegram() ? 'Returning to the chat…' : 'You can close this page and continue in the chat.'}
        </p>
      </Centered>
    );
  }

  return (
    <div className="min-h-dvh bg-surface text-on-surface flex flex-col px-4 py-6">
      <SurveyInputs
        question={question}
        progress={1}
        textSizeClass="text-[15px]"
        reduceMotion={reduceMotion}
        onRespond={(_, v) => void send({ value: v })}
        onContinueInfo={() => void send({ skip: true })}
        onOpenWebview={(url, title) => setWebview({ url, title })}
        onOpenRecorder={() => {}}
        onSkipAudio={() => void send({ skip: true })}
        onOpenKeystroke={() => setWriting(true)}
      />
      {writing && (
        <KeystrokeRecorder
          question={question}
          reduceMotion={reduceMotion}
          onCancel={() => setWriting(false)}
          onSave={(_identifier, blob, transcript, captureMode) => {
            setWriting(false);
            void blob.text().then((log) => send({ keystrokeText: transcript, captureMode, keystrokeLog: log }));
          }}
        />
      )}
      {webview && (
        <div className="fixed inset-0 z-[110] bg-surface flex flex-col">
          <header className="bg-surface-container-high px-4 py-3 flex items-center justify-between border-b border-outline-variant/30 shrink-0">
            <h2 className="font-bold text-base truncate pr-2">{webview.title}</h2>
            <button
              onClick={() => setWebview(null)}
              className="px-3 py-2 bg-surface-container-highest rounded-full text-sm font-semibold"
            >
              Close
            </button>
          </header>
          <iframe
            src={webview.url}
            title={webview.title}
            className="flex-1 w-full border-0"
            allow="autoplay; fullscreen; accelerometer; gyroscope"
          />
        </div>
      )}
    </div>
  );
}

function Centered({ children }: { children: React.ReactNode }) {
  return <div className="min-h-dvh bg-surface flex flex-col items-center justify-center text-center px-6">{children}</div>;
}
