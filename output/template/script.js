// Birmingham Ink Recipes - Interactive Features

(function() {
  'use strict';

  // State management
  const state = {
    currentView: 'table',
    searchQuery: '',
    activeFilters: new Set(),
    sortColumn: null,
    sortDirection: 'asc',
    allRecipes: [],
    allIngredients: []
  };

  // DOM elements
  let elements = {};

  // Initialize on DOM ready
  document.addEventListener('DOMContentLoaded', init);

  function init() {
    cacheElements();
    initThemeToggle();
    initViewToggle();
    initSearch();
    initFilters();
    initTableSorting();
    extractData();

    // Load saved preferences
    loadPreferences();
  }

  function cacheElements() {
    elements = {
      themeToggle: document.getElementById('themeToggle'),
      searchInput: document.getElementById('searchInput'),
      viewButtons: document.querySelectorAll('.view-btn'),
      cardView: document.getElementById('cardView'),
      tableView: document.getElementById('tableView'),
      filterPills: document.querySelectorAll('.filter-pill'),
      tableHeaders: document.querySelectorAll('th.sortable'),
      tableBody: document.querySelector('tbody'),
      cards: document.querySelectorAll('.recipe-card')
    };
  }

  // Theme Toggle
  function initThemeToggle() {
    if (!elements.themeToggle) return;

    elements.themeToggle.addEventListener('click', toggleTheme);
  }

  function toggleTheme() {
    const html = document.documentElement;
    const currentTheme = html.getAttribute('data-theme');
    const newTheme = currentTheme === 'light' ? 'dark' : 'light';

    html.setAttribute('data-theme', newTheme);
    localStorage.setItem('theme', newTheme);
  }

  function loadTheme() {
    const savedTheme = localStorage.getItem('theme') || 'light';
    document.documentElement.setAttribute('data-theme', savedTheme);
  }

  // View Toggle
  function initViewToggle() {
    if (!elements.viewButtons.length) return;

    elements.viewButtons.forEach(btn => {
      btn.addEventListener('click', () => switchView(btn.getAttribute('data-view')));
    });
  }

  function switchView(view) {
    state.currentView = view;

    elements.viewButtons.forEach(btn => {
      btn.classList.toggle('active', btn.getAttribute('data-view') === view);
    });

    if (view === 'cards') {
      elements.cardView?.classList.remove('hidden');
      elements.tableView?.classList.add('hidden');
    } else {
      elements.cardView?.classList.add('hidden');
      elements.tableView?.classList.remove('hidden');
    }

    localStorage.setItem('view', view);
  }

  // Search
  function initSearch() {
    if (!elements.searchInput) return;

    elements.searchInput.addEventListener('input', debounce(handleSearch, 300));
  }

  function handleSearch(e) {
    state.searchQuery = e.target.value.toLowerCase();
    filterAndRender();
  }

  // Filters
  function initFilters() {
    if (!elements.filterPills.length) return;

    elements.filterPills.forEach(pill => {
      pill.addEventListener('click', () => toggleFilter(pill));
    });
  }

  function toggleFilter(pill) {
    const ingredient = pill.textContent.trim();

    if (state.activeFilters.has(ingredient)) {
      state.activeFilters.delete(ingredient);
      pill.classList.remove('active');
    } else {
      state.activeFilters.add(ingredient);
      pill.classList.add('active');
    }

    filterAndRender();
  }

  // Extract data from DOM
  function extractData() {
    // Extract recipes from cards
    const cards = document.querySelectorAll('.recipe-card');
    state.allRecipes = Array.from(cards).map(card => {
      const title = card.querySelector('.card-title')?.textContent.trim() || '';
      const ingredients = Array.from(card.querySelectorAll('.ingredient-badge')).map(badge => {
        const parts = badge.textContent.trim().split(/\s+/);
        return {
          quantity: parseInt(parts[0]) || 0,
          name: parts.slice(1).join(' ')
        };
      });

      return {
        title,
        ingredients,
        element: card
      };
    });

    // Extract unique ingredients
    const ingredientSet = new Set();
    state.allRecipes.forEach(recipe => {
      recipe.ingredients.forEach(ing => ingredientSet.add(ing.name));
    });
    state.allIngredients = Array.from(ingredientSet).sort();
  }

  // Filter and render
  function filterAndRender() {
    const filtered = state.allRecipes.filter(recipe => {
      // Search filter
      if (state.searchQuery) {
        const titleMatch = recipe.title.toLowerCase().includes(state.searchQuery);
        const ingredientMatch = recipe.ingredients.some(ing =>
          ing.name.toLowerCase().includes(state.searchQuery)
        );
        if (!titleMatch && !ingredientMatch) return false;
      }

      // Ingredient filters
      if (state.activeFilters.size > 0) {
        const hasAllIngredients = Array.from(state.activeFilters).every(filter =>
          recipe.ingredients.some(ing => ing.name === filter)
        );
        if (!hasAllIngredients) return false;
      }

      return true;
    });

    renderFiltered(filtered);
  }

  function renderFiltered(recipes) {
    // Card view
    if (elements.cards.length) {
      state.allRecipes.forEach(recipe => {
        recipe.element.classList.toggle('hidden', !recipes.includes(recipe));
      });
    }

    // Table view
    if (elements.tableBody) {
      const rows = Array.from(elements.tableBody.querySelectorAll('tr'));
      rows.forEach((row, index) => {
        const recipe = state.allRecipes[index];
        if (recipe) {
          row.classList.toggle('hidden', !recipes.includes(recipe));
        }
      });
    }
  }

  // Table Sorting
  function initTableSorting() {
    if (!elements.tableHeaders.length) return;

    elements.tableHeaders.forEach((header, index) => {
      header.addEventListener('click', () => sortTable(index, header));
    });
  }

  function sortTable(columnIndex, header) {
    const table = document.querySelector('table tbody');
    if (!table) return;

    const rows = Array.from(table.rows);

    // Toggle sort direction
    const isAscending = !header.classList.contains('sort-asc');

    // Remove sorting classes from all headers
    elements.tableHeaders.forEach(th => {
      th.classList.remove('sort-asc', 'sort-desc');
    });

    // Add appropriate class to clicked header
    header.classList.add(isAscending ? 'sort-asc' : 'sort-desc');

    // Sort rows
    rows.sort((a, b) => {
      const aText = a.cells[columnIndex]?.textContent.trim() || '';
      const bText = b.cells[columnIndex]?.textContent.trim() || '';

      // Try numeric comparison first
      const aNum = parseFloat(aText);
      const bNum = parseFloat(bText);

      if (!isNaN(aNum) && !isNaN(bNum)) {
        return isAscending ? aNum - bNum : bNum - aNum;
      }

      // Fall back to string comparison
      return isAscending
        ? aText.localeCompare(bText, undefined, { numeric: true, sensitivity: 'base' })
        : bText.localeCompare(aText, undefined, { numeric: true, sensitivity: 'base' });
    });

    // Reorder table
    table.innerHTML = '';
    rows.forEach(row => table.appendChild(row));
  }

  // Load saved preferences
  function loadPreferences() {
    loadTheme();

    const savedView = localStorage.getItem('view');
    if (savedView && (savedView === 'cards' || savedView === 'table')) {
      const viewBtn = Array.from(elements.viewButtons).find(btn =>
        btn.getAttribute('data-view') === savedView
      );
      if (viewBtn) {
        viewBtn.click();
      }
    }
  }

  // Utility: Debounce
  function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
      const later = () => {
        clearTimeout(timeout);
        func.apply(this, args);
      };
      clearTimeout(timeout);
      timeout = setTimeout(later, wait);
    };
  }

  // Export functions for potential external use
  window.RecipeApp = {
    switchView,
    toggleTheme,
    filterAndRender
  };
})();
