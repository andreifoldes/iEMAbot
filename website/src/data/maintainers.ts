/**
 * The people listed under "Maintainers" on the home page. Name, role, affiliation and ORCID iD are public; the
 * affiliation comes from the public ORCID record.
 */
export type Maintainer = {
  name: string;
  role: string;
  affiliation: string;
  orcid: string;
};

export const MAINTAINERS: Maintainer[] = [
  {
    name: 'Tamas Andrei Foldes',
    role: 'Lead developer',
    affiliation: 'Surrey Sleep Research Centre, University of Surrey',
    orcid: '0000-0002-0623-9149',
  },
];
