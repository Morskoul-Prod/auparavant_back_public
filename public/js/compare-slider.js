// Comparateur avant/après (JS vanilla) : poignée déplaçable à la souris, au doigt et au clavier.
(function () {
    function init(root) {
        var pos = 50;

        function set(value) {
            pos = Math.max(0, Math.min(100, value));
            root.style.setProperty('--pos', pos + '%');
            root.setAttribute('aria-valuenow', Math.round(pos));
        }

        function fromPointer(event) {
            var rect = root.getBoundingClientRect();
            set(((event.clientX - rect.left) / rect.width) * 100);
        }

        root.addEventListener('pointerdown', function (event) {
            root.setPointerCapture(event.pointerId);
            root.focus();
            fromPointer(event);
        });
        root.addEventListener('pointermove', function (event) {
            if (root.hasPointerCapture(event.pointerId)) {
                fromPointer(event);
            }
        });

        root.addEventListener('keydown', function (event) {
            var step = event.shiftKey ? 20 : 5;
            if (event.key === 'ArrowLeft' || event.key === 'ArrowDown') set(pos - step);
            else if (event.key === 'ArrowRight' || event.key === 'ArrowUp') set(pos + step);
            else if (event.key === 'Home') set(0);
            else if (event.key === 'End') set(100);
            else return;
            event.preventDefault();
        });

        // Sélecteur facultatif de la photo ancienne à comparer
        var select = root.parentElement.querySelector('[data-compare-select]');
        if (select) {
            select.addEventListener('change', function () {
                root.querySelector('[data-compare-before]').src = select.value;
            });
        }

        set(50);
    }

    document.querySelectorAll('[data-compare]').forEach(init);
})();
