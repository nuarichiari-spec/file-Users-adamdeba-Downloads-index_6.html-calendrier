// Met à jour data/videos.json à partir des flux YouTube officiels du Paris FC et du Stade Français Paris.
// Lancé chaque jour par GitHub Actions (voir .github/workflows/update-videos.yml). Aucune clé d'API nécessaire.
import { writeFile, mkdir } from 'node:fs/promises';

const CHAINES = [
  { nom: 'Paris FC', id: 'UCYh5fX_dsAbmpoh5Ihee45A' },
  { nom: 'Stade Français', id: 'UC9HI0fd8SE_IfSaFkVKfPLA' },
];

// Vidéos à écarter (équipes féminines, Ligue des champions féminine, etc.)
const EXCLURE = /uwcl|féminin|feminin|féminine|women|u19|u17|académie/i;
// Classement : point presse / conférence, sinon match
const PRESSE = /point presse|conférence de presse|conf de presse|la conf\b/i;
// Match : seulement les matchs joués à domicile (Jean-Bouin), donc l'équipe à domicile est citée en premier.
const DOMICILE = {
  'Paris FC': /paris fc\s*[-–]\s*\S/i,
  'Stade Français': /format de stade français|stade français paris\s*\/\s*\S/i,
};

const decode = (s) => s.replace(/&quot;/g, '"').replace(/&amp;/g, '&').replace(/&#39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>');

async function lire(chaine) {
  const r = await fetch(`https://www.youtube.com/feeds/videos.xml?channel_id=${chaine.id}`);
  if (!r.ok) throw new Error(`${chaine.nom} : HTTP ${r.status}`);
  const xml = await r.text();
  return [...xml.matchAll(/<entry>([\s\S]*?)<\/entry>/g)].map((m) => {
    const e = m[1];
    const titre = decode(e.match(/<title>(.*?)<\/title>/)[1]).replace(/^📺\s*/, '').trim();
    return {
      id: e.match(/<yt:videoId>(.*?)</)[1],
      titre,
      date: e.match(/<published>(.*?)</)[1].slice(0, 10),
      source: chaine.nom,
      url: `https://www.youtube.com/watch?v=${e.match(/<yt:videoId>(.*?)</)[1]}`,
    };
  });
}

const toutes = [];
for (const c of CHAINES) {
  try { toutes.push(...(await lire(c))); } catch (err) { console.error(err.message); }
}

const videos = toutes
  .filter((v) => !EXCLURE.test(v.titre))
  .map((v) => ({ ...v, type: PRESSE.test(v.titre) ? 'presse' : DOMICILE[v.source].test(v.titre) ? 'match' : null }))
  .filter((v) => v.type)
  .sort((a, b) => b.date.localeCompare(a.date))
  .slice(0, 12);

if (videos.length === 0) { console.error('Aucune vidéo retenue : fichier existant conservé.'); process.exit(0); }
await mkdir('data', { recursive: true });
await writeFile('data/videos.json', JSON.stringify({ maj: new Date().toISOString(), videos }, null, 2));
console.log(`${videos.length} vidéos écrites dans data/videos.json`);
