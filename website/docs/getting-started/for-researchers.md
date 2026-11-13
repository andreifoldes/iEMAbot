---
title: "Getting started: for researchers"
sidebar_label: "For researchers"
description: "How to get iEMAbot for your lab: try it on your own computer, then what to ask your IT department for, what it costs, and an email you can send them."
---

# Getting started: for researchers

iEMAbot runs on a server your institution controls, so your participants' data stays with you. You don't need to set it up
yourself. Your IT department provides a small server and installs it, which takes them about an hour, and you then run
your studies from a password-protected website.

Before you ask them, we recommend trying it on your own computer. This page explains how, and then gives you what you need
to ask IT.

## Try it on your own computer first

You can run iEMAbot on your own computer in about ten minutes, using the same installer IT will use later. It starts with
a demo study, so you can try both sides straight away: the researcher dashboard, and a questionnaire as a participant
sees it. Doing this first helps you decide whether iEMAbot suits your study before anyone sets up a server, and gives you
a concrete idea of what you are asking IT for.

**What you need:** Docker Desktop ([download](https://www.docker.com/products/docker-desktop/), free for academic use in
most institutions), started and running. We have tested this on a Mac. On Windows, run the command below in a WSL
terminal. If your institution manages your computer, you may need IT to install Docker Desktop for you.

**Install.** Open a terminal (on a Mac: the Terminal app) and run:

```bash
curl -fsSL https://raw.githubusercontent.com/andreifoldes/iEMAbot/main/deploy/selfhost/install.sh | bash -s -- \
  --domain localhost --email you@example.org --no-backup-schedule
```

Use your own email address. After a minute or two it prints the addresses, the admin password and the demo study's link:

```text
Participant app:       https://localhost/pwa/
Researcher dashboard:  https://localhost/esmira/#admin
Demo study:            https://localhost/pwa/?key=demo
```

**Your browser will warn you** the first time, that the connection is not private. That is expected: the certificate comes
from your own computer, not from a certificate authority. Accept it once. In Chrome: **Advanced**, then **Proceed to localhost**.

**What to try:**

1. Log in to the dashboard with the account and password it printed.
2. Open the **demo study's link** in the same browser and answer the **Daily check-in**, or **All question types** to see
   every kind of question.
3. Back in the dashboard, open the demo study and look at the data page: your answers are there and can be downloaded.
4. Create a study of your own: add a questionnaire, plan it under **Schedule**, and see the result under **Calendar**, which
   shows when each check-in opens and when reminders would go out.

**What does not work on your own computer:**

- **Phones.** `localhost` only exists on your computer, so a phone cannot open it.
- **Installing the app and reminders.** Browsers allow these only on a site with a trusted certificate, so they stay off here.
  Try them on your phone with the demo study once IT has set up the real server.
- **Real data.** Docker Desktop's shared folders can damage the database. This copy is for trying things out only:
  never collect data from participants with it.

**When you are done**, stop it and delete it:

```bash
cd ~/iemabot && docker compose down -v && cd ~ && rm -rf ~/iemabot
```

## Why it needs its own server

IT will probably ask why you can't use something the institution already has. These are the usual reasons:

- **Your data stays on your institution's infrastructure.** Commercial survey and EMA platforms hold your participants'
  responses on the vendor's servers. Here, there is no vendor.
- **It has to run all the time.** It sends participants reminders at set times and receives answers at any hour, which
  a shared drive or a survey tool can't do.
- **Participants need nothing from an app store.** They open a link on their phone. That link needs a web address with
  HTTPS, which is why you need a server name and not only storage.
- **It's free software.** There is no licence fee (AGPL-3.0). The only cost is the server.

### Why not a computer that is always on?

Once you have tried it on your own computer, it is tempting to leave a laptop or an office PC running for the study.
Please don't. A study depends on things a personal computer can't promise:

- **Reminders are sent by the server.** If the computer sleeps, closes its lid, restarts for an update or loses power,
  reminders due in that time are not sent. Missed reminders mean missed check-ins, which you cannot recover afterwards.
- **Phones must reach it from anywhere.** Participants answer on mobile data and on other networks. That needs a fixed
  address on the internet and a trusted HTTPS certificate. A computer on an office or home network has neither, and
  university networks usually block connections from outside.
- **Participants' data needs protecting.** Laptops are carried around, lost and stolen. A server in your institution's data
  centre has controlled access, backups kept elsewhere and someone responsible for security updates. Your ethics
  approval and data protection assessment will usually say where data is stored, and IT can confirm it.
- **The database needs a real server.** On Docker Desktop we have seen the database damaged under load. On a Linux server
  it is reliable.

A small server costs less than £30 a month (see [What to ask for](#what-to-ask-for)), which is little compared with losing a participant's data.

## What to ask for

A typical EMA study has **20 to 200 participants** answering on their phones through the web app. For that, you need one
small Linux server with a web address. We give Azure prices as a guide, because many universities already use Azure for
other services and offer virtual servers to their staff:

| | Minimum | Recommended | Recommended, with voice recordings |
| --- | --- | --- | --- |
| **CPU cores** | 2 | 2 | 2 |
| **Memory** | 1 GB | 4 GB | 4 GB |
| **Disk** | 16 GB | 32 GB | 64 GB |
| **Azure equivalent** | `Standard_B2pts_v2` | `Standard_B2s` | `Standard_B2s` |
| **Cost a month** | About £7 | About £28 | About £30 |
| **Cost a year** | About £85 | About £335 | About £360 |

Prices are Microsoft Azure's public UK list prices for the machine and its disk, excluding VAT, in November 2026. Your institution may
have its own rates, may charge differently, or may host it at no charge to you, so ask them. Budget for the length of your
study **plus time afterwards** to download and archive your data.

**Which one?** The minimum should work. In a simulated test, a script acted as 200 participants all sending a short check-in
at the same moment, as they might just after a reminder. All 200 were saved, typically within about a quarter of a second,
using about half the memory. That was not a test with real phones or a real study. The minimum also leaves little room for the unexpected and for future versions, so choose the recommended size if the
cost is not a problem, and always if participants record audio, since the minimum disk has no room for them.

## The email to send

Copy this, fill in the parts in square brackets and send it to your IT service desk or research computing team. It says
what you need and links them to [their setup guide](./for-it.md).

```text
Subject: Request for a small Linux server for a research study

Hello,

I am planning a research study and would like to ask whether IT can provide a
small server for it, and what that would cost.

The study: [one sentence about your study], with about [number] participants
over [number] weeks, starting around [month and year]. Participants answer short
questionnaires on their own phones several times a day. [We will / will not]
collect voice recordings. [We will / will not] collect data from participants'
wearable devices ([Withings / Google Health]), in which case the server would also
exchange data with that provider. The study [has / is applying for] ethics approval:
[reference, if you have one].

The software: iEMAbot, a free, open-source research tool for this kind of study
(https://github.com/andreifoldes/iEMAbot). We need to host it ourselves so that
participants' data stays on infrastructure the institution controls, rather than
with an outside vendor.

What we would need:
- One Linux virtual machine (Ubuntu or Debian), in one of these sizes:
    Minimum:     2 CPU cores, 1 GB memory, 16 GB disk, questionnaires only
                 (for example Azure Standard_B2pts_v2)
    Recommended: 2 CPU cores, 4 GB memory, [32 GB / 64 GB with voice recordings] disk
                 (for example Azure Standard_B2s)
  We would prefer the [recommended / minimum] size.
- One web address, for example [ema.our-department.ac.uk], reachable from the
  internet, because participants use their own phones, on mobile data or wifi
- HTTPS. The software can get its own certificate, or it can sit behind your
  existing web server or load balancer
- Regular backups kept away from the server
- Someone to apply updates from time to time, which is a single command

It needs to run for the length of the study, plus about [number] months for us
to download and archive the data.

There is a setup guide written for IT staff, including network requirements,
what data is stored where, and security notes:
https://andreifoldes.github.io/iEMAbot/getting-started/for-it

Could you let me know:
1. Whether this is possible, and who would look after it
2. What it would cost, and whether it can be charged to [grant or cost code]
3. Whether you need a security or data protection review first, and how long that
   usually takes
4. Roughly how long it would take to set up

Thank you,
[Your name, role, department, contact details]
```

## Decisions that stay with you

IT can run the server, but these are for you and your institution's research governance team:

- **Ethics approval and consent.** Your consent form should say what is collected, and where it is kept.
- **Data protection.** You may need a data protection impact assessment (DPIA). We don't have a template yet, but
  [Data and privacy](../backend/data-and-privacy.md) describes what is stored and which third parties are involved. Reminders
  pass through the push services of Google, Apple and Mozilla, in encrypted form. If you collect wearable data, the wearable
  provider is a third party too, and participants need to agree to link their account.
- **How long you keep the data**, and who may log in to the researcher dashboard.

Start these early. In many institutions a review takes longer than setting up the server.

## How to estimate your storage

If your study differs from the table above, here are the figures to work from. Answers take very little space, but voice
recordings add up, and backups multiply whatever you store.

| Example study | Data collected |
| --- | --- |
| 20 participants, 14 days, 5 check-ins a day | Under 10 MB |
| 200 participants, 30 days, 5 check-ins a day | Under 200 MB |
| 200 participants, 30 days, one 1-minute voice memo a day | About 6 GB more, at roughly 1 MB a minute |

The server keeps its last seven nightly backups, so with voice recordings allow about **eight times your data**, plus about
4 GB for the system. If that is too much, IT can keep fewer copies on the server, and a cloud disk can usually be enlarged later.

## Once it is set up

IT will give you an address and an admin account. Then:

1. Log in to the dashboard and **change the password**.
2. Create a study and add a questionnaire.
3. Under **Publication, Links & QR code**, choose **Study is published** and add an access key. Choosing a long, random one
   keeps people who guess it out of your study.
4. Send participants the **Link for participants** shown there, or print its QR code.

Try the whole thing on your own phone, including receiving a reminder, before you recruit anyone. The server starts with the
demo study, which shows every question type: open `/pwa/?key=demo` on the server's address on your phone, add it to your Home
Screen when asked, and answer **Set a test reminder**. A reminder arrives at the time you chose. **Delete the demo study before
you recruit**, because its access key is public.

### Who can join: open, or only IDs on your list

By default anyone who has the access key can join, and the app gives each participant a random ID. If you give each participant an
ID yourself, open **Participant IDs** in the study editor and choose **Only participants on my list can join**:

- **Make the list.** Paste the IDs (one per line), upload a text or CSV file, or press **Make random IDs**. Random IDs cannot be
  guessed; P001, P002 can, and an ID works like a password. Save the list with its own button.
- **Personal links.** Download the CSV of personal links, one per listed ID, and send each participant theirs. With **Take the ID
  from the invite link** on, the link carries the ID and the participant types nothing. Switch it off, or send the plain link,
  and the app asks the participant to type their ID.
- **What happens to an unlisted ID.** The app says the ID is not recognised and stores and sends nothing; the server also refuses
  it. A participant who types the ID in another letter case (`p001` for `P001`) is let in as `P001`.
- **Joined or not.** The page shows which listed IDs have joined. Participants who joined before you switched to a list keep
  working, even if they are not on it; to remove someone, withdraw them from the study.

### One device or several per ID

Under **Devices per participant ID** on the same page you choose whether an ID may be used on several devices at once (the
default) or on one at a time. With **One device at a time per ID**, the first device to use an ID holds it and the server refuses
the others. A participant who changes phone opens the study on the new one and taps **Transfer to this device**, or signs out on
the old one first. Answers waiting on a refused device are kept and sent once the ID is taken back. This works with open and
list studies alike, and only for participants who have updated the app: older versions are not restricted. Clearing the browser's
data makes that device a new one, which needs a transfer.

## Doing it without IT

If your institution allows it, you can rent a small server from a commercial provider and follow
[Self-host for your lab](../deployment/self-hosting.md) yourself. You then carry the responsibility for its security, updates and
backups, so check with your data protection office first.
