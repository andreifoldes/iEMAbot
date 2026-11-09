import React from 'react';
import {MAINTAINERS} from '../data/maintainers';

/** The maintainer list for the home page (data in ../data/maintainers.ts). */
export function Maintainers(): React.JSX.Element {
  return (
    <ul>
      {MAINTAINERS.map((m) => (
        <li key={m.orcid}>
          <strong>{m.name}</strong>, {m.role}. {m.affiliation}.{' '}
          <a href={`https://orcid.org/${m.orcid}`}>ORCID {m.orcid}</a>
        </li>
      ))}
    </ul>
  );
}
