# Adresses lisibles et pages de partage

## Pourquoi

Le front est une SPA : son HTML initial est vide. Les robots des réseaux sociaux (WhatsApp, Facebook,
iMessage, Slack, X…) ne lisent que ce HTML, donc un lien de souvenir s'afficherait sans titre ni photo.
Le back fournit donc une page HTML minimale (titre, description, photo, balises Open Graph et Twitter) pour
chaque souvenir et chaque ville.

## Adresses

| Adresse | Rôle |
|---|---|
| `/souvenirs/{id}-{slug}` | Page de partage d'un souvenir. L'id fait foi ; `{id}` seul ou un slug périmé renvoient une **301** vers l'adresse à jour. 404 si le souvenir n'existe pas (`noindex`). |
| `/lieux/{ville}` | Page de partage d'une ville (nombre de souvenirs, liste, photo du plus récent). 404 sans souvenir. |

- Le slug se calcule par `App\Service\Slug` (`AsciiSlugger`, minuscules, 80 caractères au plus) : titre + ville
  pour un souvenir. Il n'est pas stocké : `Memory::getSlug()` et `Location::getCitySlug()` le calculent.
- L'API l'expose : `slug` dans les souvenirs (`GET /api/memory/{id}`, listes via `MemoryProcessor`) et
  `location.citySlug`. Le front s'en sert pour fabriquer ses liens (repli : `src/utils/slug.ts`, qui doit rester
  aligné sur `Slug.php`).
- L'adresse canonique (`og:url`, `<link rel="canonical">`) est celle du **front** (`FRONT_URL`, sans slash final) ;
  les visiteurs y sont renvoyés par un `window.location.replace`.
- Réponses en cache public 5 minutes.

## Configuration

- `FRONT_URL` (`.env`, `http://localhost:5173` par défaut) : adresse publique du front.
- Derrière un reverse proxy, déclarer les proxys de confiance (`framework.trusted_proxies`) pour que l'URL absolue
  de l'image (`og:image`, construite depuis la requête) soit en `https` et sur le bon domaine.

## Déploiement : envoyer les robots vers le back

Le serveur web du front doit transmettre au back les requêtes des robots de partage sur `/souvenirs/*` et
`/lieux/*`, et servir la SPA aux autres. Exemple nginx (à adapter) :

```nginx
map $http_user_agent $share_bot {
    default 0;
    ~*(facebookexternalhit|Facebot|WhatsApp|Twitterbot|Slackbot|TelegramBot|LinkedInBot|Discordbot|iMessage|Applebot|Googlebot|bingbot) 1;
}

location ~ ^/(souvenirs|lieux)/ {
    if ($share_bot) {
        proxy_pass https://api.exemple.fr;   # le back, même chemin
    }
    try_files $uri /index.html;               # visiteurs : la SPA
}
```

Sans cette règle, les pages existent mais personne ne les demande : les liens partagés restent sans aperçu.
Tester avec `curl -A "WhatsApp/2.0" https://front/souvenirs/42` puis avec les outils de débogage des plateformes
(Facebook Sharing Debugger…). Les plateformes mettent les aperçus en cache : un test après correction peut
demander de les rafraîchir.

## Limites et suite possible

- Pas de `sitemap.xml` ni de `robots.txt` pour l'instant (la liste des souvenirs et des villes s'y prête).
- Pas de balise `og:image` quand le souvenir n'a aucune photo approuvée (carte Twitter `summary`).
