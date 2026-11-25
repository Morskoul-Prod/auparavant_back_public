import { Controller } from '@hotwired/stimulus';

/*
 * Exemple de contrôleur Stimulus !
 *
 * Tout élément portant l'attribut data-controller="hello" provoque
 * l'exécution de ce contrôleur. Le nom « hello » vient du nom du fichier :
 * hello_controller.js -> "hello"
 *
 * Supprimer ce fichier ou l'adapter à votre usage !
 */
export default class extends Controller {
    connect() {
        this.element.textContent = 'Hello Stimulus! Edit me in assets/controllers/hello_controller.js';
    }
}
