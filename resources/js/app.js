import { createContextualHelp } from './contextual-help';
import './bootstrap';
import { createAppNotification } from './app-notification';
import { createClassificationPrompt } from './classification-prompt';
import { createDirtyStateRegistry } from './dirty-state-registry';
import { consumeIngredientEditorNotification, createIngredientEditor } from './ingredient-editor';
import { createIngredientDuplicationModal } from './ingredient-duplication';
import { createMediaAssetPicker } from './media-asset-picker';
import { createMediaLibraryUploader } from './media-library-uploader';
import { createRecipeContentAutosave } from './recipe-content-autosave';
import { createRecipeWorkbench } from './recipe-workbench/component';
import { createSearchCombobox } from './search-combobox';
import { createStickyTableHeader } from './sticky-table-header';
import { createProductionCalendar, createProductionCalendarComponent } from './production-calendar';
import { initializeProductCreationSelectors } from './product-creation-selector';
import {
    createSidebarController,
    DESKTOP_MEDIA_QUERY,
    SIDEBAR_STORAGE_KEY,
} from './sidebar';

window.appNotification = createAppNotification;
window.classificationPrompt = createClassificationPrompt;
window.recipeContentAutosave = createRecipeContentAutosave;
window.mediaAssetPicker = createMediaAssetPicker;
window.mediaLibraryUploader = createMediaLibraryUploader;
window.recipeWorkbench = (payload) => createRecipeWorkbench(payload, createDirtyStateRegistry);
window.ingredientEditor = (payload) => createIngredientEditor(payload, createDirtyStateRegistry);
window.ingredientDuplicationModal = (payload) => createIngredientDuplicationModal(payload);
window.searchCombobox = createSearchCombobox;
window.stickyTableHeader = createStickyTableHeader;
window.productionCalendar = createProductionCalendar;
window.productionCalendarComponent = createProductionCalendarComponent;

const sidebarController = createSidebarController({
    document,
    window,
    storageKey: SIDEBAR_STORAGE_KEY,
    mediaQuery: DESKTOP_MEDIA_QUERY,
    applySidebarWidth: ({ isDesktop, nextOpen }) => {
        document.documentElement.style.setProperty('--app-sidebar-width', isDesktop && nextOpen ? '17rem' : '0rem');
    },
});

function initializeSidebar({ initial = false } = {}) {
    const result = initial
        ? sidebarController.initialize()
        : sidebarController.refresh();

    initializeProductCreationSelectors();

    return result;
}

sidebarController.mount();
document.addEventListener('DOMContentLoaded', () => initializeSidebar({ initial: true }));
document.addEventListener('livewire:navigated', () => {
    initializeSidebar();
    queueMicrotask(() => consumeIngredientEditorNotification());
});

const contextualHelp = createContextualHelp({ document, window });
contextualHelp.mount();
