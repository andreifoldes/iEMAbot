import type {SidebarsConfig} from '@docusaurus/plugin-content-docs';

const sidebars: SidebarsConfig = {
  docs: [
    'index',
    {
      type: 'category',
      label: 'Getting started',
      collapsed: false,
      items: ['getting-started/for-researchers', 'getting-started/for-it'],
    },
    'current-status',
    'fork-divergence',
    'architecture',
    'help-development',
    {
      type: 'category',
      label: 'Backend (ESMira)',
      collapsed: false,
      items: [
        'backend/overview',
        'backend/study-model',
        'backend/create-study-cli',
        {
          type: 'category',
          label: 'Question types',
          className: 'sidebar-highlight',
          collapsed: false,
          link: {type: 'doc', id: 'backend/question-types/index'},
          items: ['backend/question-types/cognitive-tasks'],
        },
        'backend/scheduling',
        {
          type: 'category',
          label: 'Channels',
          collapsed: false,
          link: {type: 'doc', id: 'backend/channels/index'},
          items: [
            'backend/channels/web-push',
            'backend/channels/chat-channels',
            'backend/channels/android-app',
            'backend/channels/phone-sensing',
          ],
        },
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
        'pwa/offline-and-uploads',
        'pwa/accessibility',
      ],
    },
    {
      type: 'category',
      label: 'Operations',
      items: [
        'deployment/self-hosting',
        'deployment/docker-and-cron',
        'deployment/demo-and-verification',
        'deployment/ci-and-release',
        'deployment/android-signing',
        'deployment/security-audit',
        'deployment/security-headers',
      ],
    },
  ],
};

export default sidebars;
