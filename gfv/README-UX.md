# Glossaire UX v2 — guide de déploiement

This folder contains a scoped, progressive-enhancement redesign for **/gfv/**.
The main website remains unchanged. The production branch is not modified by this feature branch.

## UX
- Desktop: search and dependent Domaine / Thème filters, a scrollable 25-word list on the left, selected word on the right.
- Mobile: controls stack, short scrolling list above the active entry.
- French-first: definition, context example, synonyms, collocations; Vietnamese translation behind an explicit disclosure.
- Optional image shown using **the actual** glossary JSON field \`imageURL\`. Missing or broken image links are quietly omitted; the app does not invent an image.
- IPA from \`pronunciation\`; bookmarked words stored in the current browser's localStorage.
- Search supports accents (e.g. \`Petrir\` matches \`pétrir\`) and maintains query parameters in the URL.

## Source data
The build currently publishes **/data/glossary.json** as a static snapshot of the original Google Sheet, not a direct live Google Sheets API connection. Keep your existing Sheet → JSON publication process. JSON keys used:
\`id\`, \`word\`, \`pronunciation\`, \`type\`, \`grammar\`, \`definition\`, \`example\`,
\`collocations\`, \`notes\`, \`synonyms\`, \`domain\`, \`theme\`, \`family\`,
\`vietnamese\`, \`imageURL\`.

At the time of the redesign the JSON contained 900 entries and 283 non-empty image URLs. The entry \`pétrir\` has no image URL.

## Speech (optional Azure configuration)
The browser calls the same-origin Netlify Function at
\`/.netlify/functions/pronounce\`.

In Netlify, set the **server-only** environment variables:
- \`AZURE_SPEECH_KEY\` — Azure Speech resource key.
- \`AZURE_SPEECH_REGION\` — Azure Speech resource region, e.g. \`francecentral\` (use the actual region of your resource).

Redeploy after configuring variables. The function calls Azure Neural TTS with
\`fr-FR-HenriNeural\`, male, French (France), MP3. This is an Azure locale/voice choice, not a certified “Paris accent” claim; audition the resulting audio before treating it as your final pedagogical voice.

**Without these variables:** The function replies 503. The UI attempts the browser's \`SpeechSynthesis\` instead and labels it as a browser voice whose accent/gender are not guaranteed. No key is embedded in public JavaScript.

**Operational risk:** The server endpoint is publicly callable. Before a widely promoted launch, configure Azure cost alerts and limits, and plan rate limiting if usage scales.

## Testing checklist
1. Browse \`/gfv/?q=Petrir\`: \`pétrir\` should be selected despite omission of accent.
2. Select \`Gastronomie\` then a Thème: only themes in that domain should appear.
3. Verify search/filter reset, pagination, word selection, bookmark, and browser refresh URL.
4. Confirm Vietnamese remains hidden until opened.
5. Confirm images appear only for rows with working public \`imageURL\`.
6. Test mobile (~375px) and desktop (~1280px) with a keyboard.
7. Test pronunciation first without Azure config (explicit fallback), then with Azure environment variables.
8. Confirm unrelated pages are unchanged and the original non-JS glossary still loads if scripts are unavailable.

Do not merge the branch into \`main\` or publish production changes until the review is approved.
