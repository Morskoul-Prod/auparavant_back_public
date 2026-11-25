// Rafraîchit la pastille « photos à modérer » de la barre latérale (polling)
(function () {
    // Sidebar et tableau de bord : toutes les pastilles partagent le même compteur
    const badges = document.querySelectorAll('.js-moderation-badge');
    if (badges.length === 0) {
        return;
    }

    const INTERVALLE_MS = 60000;

    async function rafraichir() {
        // Inutile d'interroger le serveur quand l'onglet n'est pas visible
        if (document.hidden) {
            return;
        }
        try {
            const reponse = await fetch(badges[0].dataset.url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!reponse.ok) {
                return;
            }
            const { count } = await reponse.json();
            badges.forEach((badge) => {
                badge.textContent = count;
                badge.hidden = count === 0;
            });
        } catch (e) {
            // Erreur réseau ponctuelle : on réessaiera au prochain cycle
        }
    }

    setInterval(rafraichir, INTERVALLE_MS);
    // Mise à jour immédiate au retour sur l'onglet
    document.addEventListener('visibilitychange', rafraichir);
})();
