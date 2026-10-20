<p align="center">
	<img src="https://raw.githubusercontent.com/KL-Psychological-Methodology/ESMira/main/about/images/web_header_normal.svg" alt="ESMira" width="300"/>
	<br>
	<a href="https://demo-esmira.kl.ac.at/">[Demo]</a>
	<a href="https://esmira.kl.ac.at/?about">[Screenshots]</a>
	<a href="https://github.com/KL-Psychological-Methodology/ESMira/wiki">[Wiki]</a>
	<a href="https://github.com/andreifoldes/iEMAbot/discussions">[Discussions]</a>
</p>

**iEMAbot** is an open-source framework for ecological momentary assessment (EMA) in circadian and sleep research, developed at the Surrey Sleep Research Centre and the UK Dementia Research Institute Care Research & Technology Centre. It is a fork of [ESMira](https://github.com/KL-Psychological-Methodology/ESMira), the open-source experience-sampling platform from Karl Landsteiner University, and keeps ESMira's study designer, data collection and server.

**Why we forked.** Understanding how sleep and circadian rhythms shape daytime functioning takes repeated self-report and task data collected in daily life. Dedicated EMA platforms are often costly and complex (subscriptions, proprietary apps, technical infrastructure), and unfamiliar app interfaces reduce engagement and adherence, especially in older or cognitively vulnerable people. ESMira gave us an open, self-hostable base. We forked it to add what our studies need and upstream does not offer: delivery through a familiar chat-style interface, and prompts that fire at the right moment in the sleep-wake cycle.

**Goals**
- **Meet participants where they are.** An installable web app (no app store) with a chat-style interface, plus a Telegram bot as a proof of concept for chat delivery. The channel layer is built to be extended to WhatsApp and SMS.
- **Prompt at the right moment.** Flexible "if this, then that" scheduling (time-contingent, event-contingent and adaptive designs, multi-point daily sampling), including prompts triggered by wearables and nearables, for example when a Withings sleep sensor reports that someone got out of bed, with a time fallback if no event arrives. Fitbit and Pixel Watch data come through Google Health.
- **Go beyond questionnaires.** Cognitive tasks (M2C2, a psychomotor vigilance test), voice memos and typing-dynamics capture, alongside validated sleep and alertness scales.
- **Keep it affordable and under your control.** A self-hosted server that runs on a low-cost VPS or your institution's own cloud tenant, so data stay on infrastructure you manage.
- **Be accessible.** WCAG 2.2 AA is a deploy gate for the participant app.
- **Stay open.** Everything is AGPL-3.0, documented, and built for community extension.

Several of these are early. [Current status](https://andreifoldes.github.io/iEMAbot/current-status) lists, claim by claim, what is shipped, partial or not yet built, and the [documentation](https://andreifoldes.github.io/iEMAbot/) covers setup and study design. For upstream ESMira, see its [main Readme](https://github.com/KL-Psychological-Methodology/ESMira) and [server setup guide](https://github.com/KL-Psychological-Methodology/ESMira/wiki/Setting-up-a-server).


<p align="center">
	<img src="website/static/img/mockup-telegram.svg" height="560" alt="Illustrative mockup of the check-in in a Telegram chat: a 1 to 5 rating, a nine-point sleepiness scale and a reaction-time game offered with Start task, Skip task, Remind me later and Skip check-in buttons."/>
	&nbsp;&nbsp;&nbsp;
	<img src="website/static/img/mockup-pwa.svg" height="560" alt="Illustrative mockup of the same check-in in the participant web app: the same rating, sleepiness scale and reaction-time game as cards in a chat-style layout."/>
	<br>
	<em>One study, two ways to answer: the same check-in as a Telegram conversation (left) and in the installable participant web app (right). Illustrative mockups.</em>
</p>


## :handshake: Contributing and discussion
This repository is the iEMAbot fork of the ESMira web server (study design interface, data collection and the participant PWA). Its [documentation](https://andreifoldes.github.io/iEMAbot/) describes what has changed and what each feature does.

**Questions, ideas and feedback are welcome in [Discussions](https://github.com/andreifoldes/iEMAbot/discussions).** Ask how something works, propose a feature, or show the study you built with it. Not sure whether something is a bug? Start a discussion and we will work it out together.

**Pull requests are welcome too.** Fixes, features, tests and documentation improvements all help.
1. Fork the repository and branch from `main`.
2. Try your change with the one-command demo, which builds the image, creates an admin and a demo study, and checks both the participant app and the researcher dashboard: `scripts/demo/demo-up.sh` (details: [Demo and verification](https://andreifoldes.github.io/iEMAbot/deployment/demo-and-verification)).
3. Use [Conventional Commits](https://www.conventionalcommits.org/) for your commit messages (for example `fix(pwa): ...` or `docs(website): ...`).
4. Open a pull request against `main` and describe what changed and how you tested it. A short draft PR to talk an idea through is fine.

The documentation site lives in `website/` and is deployed from `main`. A security check scans every push and pull request for private details, so please never include credentials, server addresses, study access keys or participant data in code, docs or discussions. If you think you have found a security vulnerability, do not post it publicly: open a discussion asking for a private way to share it, without including the details.

## :link: Resources
- [Main repository](https://github.com/KL-Psychological-Methodology/ESMira)
- [Website](https://esmira.kl.ac.at/?about)
- [Wiki](https://github.com/KL-Psychological-Methodology/ESMira/wiki)
- [Discussions](https://github.com/andreifoldes/iEMAbot/discussions)
- [iEMAbot documentation](https://andreifoldes.github.io/iEMAbot/)
- [Demo Server](https://demo-esmira.kl.ac.at/)
- [Help development](https://github.com/KL-Psychological-Methodology/ESMira/wiki/Help-development)