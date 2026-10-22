import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();

if (window.location.pathname.startsWith('/lms/materials/')) {
    import('./lms-material-editor.js');
}