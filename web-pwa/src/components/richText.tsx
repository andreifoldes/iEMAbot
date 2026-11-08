import { Fragment, type ReactNode } from 'react';

/**
 * Renders translated text that carries light markup: `**bold**` becomes <strong>, and `{name}`
 * is replaced by `slots.name` (an icon or label that cannot be part of the translated string).
 * Keeping the whole sentence in one translation key lets translators reorder the pieces.
 */
export function rich(text: string, opts: { strongClass?: string; slots?: Record<string, ReactNode> } = {}): ReactNode {
  const parts = text.split(/(\*\*[^*]+\*\*|\{[a-z]+\})/);
  return (
    <>
      {parts.map((part, i) => {
        if (part.length > 4 && part.startsWith('**') && part.endsWith('**')) {
          return <strong key={i} className={opts.strongClass}>{part.slice(2, -2)}</strong>;
        }
        const slot = /^\{([a-z]+)\}$/.exec(part);
        if (slot && opts.slots && opts.slots[slot[1]] !== undefined) {
          return <Fragment key={i}>{opts.slots[slot[1]]}</Fragment>;
        }
        return part;
      })}
    </>
  );
}
