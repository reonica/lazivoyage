'use strict';

/**
 * Azure Speech proxy for the French-first glossary.
 *
 * Configure AZURE_SPEECH_KEY and AZURE_SPEECH_REGION as Netlify
 * environment variables. Never expose either value in the browser.
 * When not configured, responds with 503 and the client falls back
 * to the browser's speech synthesis (which may not have a male voice).
 */
const MAX_LENGTH = 260;
const xmlEscape = value => value.replace(/[<>&"']/g, char =>
  ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;',"'":'&apos;'})[char]);

exports.handler = async event => {
  if (event.httpMethod !== 'POST') {
    return { statusCode:405, headers:{Allow:'POST'}, body:'Method not allowed' };
  }
  const type = String(event.headers?.['content-type'] || event.headers?.['Content-Type'] || '').toLowerCase();
  if (!type.includes('application/json')) {
    return { statusCode:415, body:'JSON required' };
  }
  let input;
  try { input = JSON.parse(event.body || '{}'); }
  catch (_) { return { statusCode:400, body:'Invalid JSON' }; }
  const value = typeof input.text === 'string' ? input.text.trim() : '';
  if (!value || [...value].length > MAX_LENGTH) {
    return { statusCode:400, body:'Text must be between 1 and 260 characters' };
  }

  const key = process.env.AZURE_SPEECH_KEY;
  const region = process.env.AZURE_SPEECH_REGION;
  if (!key || !region || !/^[a-z0-9-]+$/i.test(region)) {
    return { statusCode:503, headers:{'Cache-Control':'no-store'}, body:'Speech service not configured' };
  }

  const ssml = '<speak version="1.0" xmlns="http://www.w3.org/2001/10/synthesis" xml:lang="fr-FR">' +
    '<voice name="fr-FR-HenriNeural"><prosody rate="-8%">' + xmlEscape(value) +
    '</prosody></voice></speak>';
  try {
    const response = await fetch('https://' + region + '.tts.speech.microsoft.com/cognitiveservices/v1', {
      method:'POST',
      headers:{
        'Ocp-Apim-Subscription-Key':key,
        'Content-Type':'application/ssml+xml; charset=utf-8',
        'X-Microsoft-OutputFormat':'audio-24khz-48kbitrate-mono-mp3',
        'User-Agent':'LaziVoyageGlossary/1.0'
      },
      body:ssml,
      signal:AbortSignal.timeout(12000)
    });
    if (!response.ok) {
      console.error('Azure Speech synthesis failure, status', response.status);
      return { statusCode:502, headers:{'Cache-Control':'no-store'}, body:'Speech synthesis failed' };
    }
    const audio = Buffer.from(await response.arrayBuffer());
    return {
      statusCode:200,
      headers:{
        'Content-Type':'audio/mpeg',
        'Cache-Control':'private, max-age=86400',
        'X-Content-Type-Options':'nosniff'
      },
      isBase64Encoded:true,
      body:audio.toString('base64')
    };
  } catch (error) {
    console.error('Azure Speech request error:', error.message);
    return { statusCode:502, headers:{'Cache-Control':'no-store'}, body:'Speech temporarily unavailable' };
  }
};
