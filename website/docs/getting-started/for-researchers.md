---
title: "Getting started: for researchers"
sidebar_label: "For researchers"
description: "How to get iEMAbot for your lab: what to ask your IT department for, what it costs, and an email you can send them."
---

# Getting started: for researchers

iEMAbot runs on a server your institution controls, so your participants' data stays with you. You don't need to set it up
yourself. Your IT department provides a small server and installs it, which takes them about an hour, and you then run
your studies from a password-protected website.

This page gives you what you need to ask them.

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

## Why it needs its own server

IT will probably ask why you can't use something the institution already has. These are the usual reasons:

- **Your data stays on your institution's infrastructure.** Commercial survey and EMA platforms hold your participants'
  responses on the vendor's servers. Here, there is no vendor.
- **It has to run all the time.** It sends participants reminders at set times and receives answers at any hour, which
  a shared drive or a survey tool can't do.
- **Participants need nothing from an app store.** They open a link on their phone. That link needs a web address with
  HTTPS, which is why you need a server name and not only storage.
- **It's free software.** There is no licence fee (AGPL-3.0). The only cost is the server.

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

Try the whole thing on your own phone, including receiving a reminder, before you recruit anyone. You can also ask IT to add the demo study
(see [their guide](./for-it.md#hand-over)), which shows every question type, so you can see what participants would see. It also lets you test reminders: answer **Set a test reminder**, and one arrives at the time you chose.

## Doing it without IT

If your institution allows it, you can rent a small server from a commercial provider and follow
[Self-host for your lab](../deployment/self-hosting.md) yourself. You then carry the responsibility for its security, updates and
backups, so check with your data protection office first.
