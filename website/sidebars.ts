import type {SidebarsConfig} from '@docusaurus/plugin-content-docs';

const sidebars: SidebarsConfig = {
  docs: [
    'index',
    'current-status',
    'fork-divergence',
    {
      type: 'category',
      label: 'Backend (ESMira)',
      collapsed: false,
      items: [
        'backend/overview',
        'backend/study-model',
        'backend/scheduling',
        'backend/web-push',
        'backend/chat-channels',
        'backend/wearables',
        'backend/api-reference',
        'backend/sqlite-store',
        'backend/data-and-privacy',
      ],
    },
    {
      type: 'category',
      label: 'Participant PWA',
      collapsed: false,
      items: [
        'pwa/overview',
        'pwa/participant-flow',
        'pwa/question-types',
        'pwa/cognitive-tasks',
        'pwa/offline-and-uploads',
        'pwa/accessibility',
      ],
    },
    {
      type: 'category',
      label: 'Operations',
      items: [
        'deployment/docker-and-cron',
        'deployment/demo-and-verification',
        'deployment/ci-and-release',
        'deployment/security-audit',
      ],
    },
  ],
};

export default sidebars;
