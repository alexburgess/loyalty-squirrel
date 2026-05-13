(function () {
  function debounce(fn, wait) {
    var timeout;
    return function () {
      var context = this;
      var args = arguments;
      clearTimeout(timeout);
      timeout = setTimeout(function () {
        fn.apply(context, args);
      }, wait);
    };
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function formatPoints(amount) {
    var config = window.loyaltyPointsAdmin || {};
    var value = Number(amount) || 0;
    var absValue = Math.abs(value);
    var label = absValue === 1
      ? config.labelSingular || 'Loyalty Point'
      : config.labelPlural || 'Loyalty Points';
    try {
      return new Intl.NumberFormat(navigator.language || 'en-US').format(value) + ' ' + label;
    } catch (error) {
      return String(value) + ' ' + label;
    }
  }

  function initRoleCountInfo() {
    var roleSelect = document.getElementById('credits_role');
    var info = document.getElementById('credits-role-count');
    if (!roleSelect || !info) {
      return;
    }

    function syncRoleCount() {
      if (!roleSelect.value) {
        info.textContent = 'Select a role to see account count.';
        return;
      }
      var option = roleSelect.options[roleSelect.selectedIndex];
      var count = option ? option.getAttribute('data-count') || '0' : '0';
      info.textContent = 'Selected role currently has ' + count + ' account(s).';
    }

    roleSelect.addEventListener('change', syncRoleCount);
    syncRoleCount();
  }

  function initOperationControls(formSelector, options) {
    var form = document.querySelector(formSelector);
    if (!form) {
      return;
    }

    var config = window.loyaltyPointsAdmin || {};
    var operationInputs = form.querySelectorAll('input[name="operation"]');
    var amountInput = form.querySelector(options.amountSelector);
    var amountRow = amountInput ? amountInput.closest('tr') : null;
    var amountWrap = form.querySelector(options.amountWrapSelector);
    var amountLabel = form.querySelector(options.amountLabelSelector);
    var noteInput = form.querySelector(options.noteSelector);
    var noteRow = noteInput ? noteInput.closest('tr') : null;
    var submitButton = form.querySelector(options.submitSelector);
    var preview = form.querySelector('#credits-balance-preview');
    var currentBalance = parseInt(form.getAttribute('data-current-balance') || '0', 10);

    if (!operationInputs.length || !amountInput || !noteInput) {
      return;
    }

    function getSelectedOperation() {
      var selected = form.querySelector('input[name="operation"]:checked');
      return selected ? selected.value : '';
    }

    function setOptionStates(operation) {
      operationInputs.forEach(function (input) {
        var option = input.closest('.credits-operation-option');
        if (option) {
          option.classList.toggle('is-selected', input.value === operation);
        }
      });
    }

    function setAmountTone(operation) {
      if (!amountWrap) {
        return;
      }
      amountWrap.classList.remove('credits-tone-money', 'credits-tone-danger', 'credits-tone-set');
      if (operation === 'deduct') {
        amountWrap.classList.add('credits-tone-danger');
      } else if (operation === 'set') {
        amountWrap.classList.add('credits-tone-set');
      } else {
        amountWrap.classList.add('credits-tone-money');
      }
    }

    function setFormEnabledState(operation) {
      var enabled = operation !== '';
      if (amountRow) {
        amountRow.style.display = enabled ? 'table-row' : 'none';
      }
      if (noteRow) {
        noteRow.style.display = enabled ? 'table-row' : 'none';
      }
      amountInput.disabled = !enabled;
      noteInput.disabled = !enabled;
      if (submitButton) {
        submitButton.disabled = !enabled;
      }
    }

    function syncLabels(operation) {
      if (amountLabel) {
        amountLabel.textContent =
          operation === 'set'
            ? options.setAmountLabel || config.newBalanceAmountLabel || 'New balance'
            : options.amountLabel || config.amountLabel || 'Points';
      }

      var placeholder = config.notePlaceholderDefault || 'Required reason for this Square adjustment';
      if (options.isRole) {
        placeholder = config.roleNotePlaceholderDefault || placeholder;
        if (operation === 'add') {
          placeholder = config.roleNotePlaceholderAdd || placeholder;
        } else if (operation === 'deduct') {
          placeholder = config.roleNotePlaceholderDeduct || placeholder;
        } else if (operation === 'set') {
          placeholder = config.roleNotePlaceholderSet || placeholder;
        }
      } else if (operation === 'add') {
        placeholder = config.notePlaceholderAdd || placeholder;
      } else if (operation === 'deduct') {
        placeholder = config.notePlaceholderDeduct || placeholder;
      } else if (operation === 'set') {
        placeholder = config.notePlaceholderSet || placeholder;
      }
      noteInput.setAttribute('placeholder', placeholder);
    }

    function syncProjectedBalance(operation) {
      if (!preview) {
        return;
      }
      if (!operation) {
        preview.textContent = config.previewSelectOperation || 'Select an operation to see the projected balance.';
        preview.classList.remove('is-error');
        return;
      }

      var amount = parseInt(amountInput.value || '0', 10);
      if (!Number.isFinite(amount) || amount < 0) {
        preview.textContent = config.previewEnterAmount || 'Enter a point amount to preview the new balance.';
        preview.classList.remove('is-error');
        return;
      }

      var projected = currentBalance;
      if (operation === 'add') {
        projected = currentBalance + amount;
      } else if (operation === 'deduct') {
        projected = currentBalance - amount;
        if (projected < 0) {
          preview.textContent = config.previewInsufficient || 'Removal exceeds current balance.';
          preview.classList.add('is-error');
          return;
        }
      } else if (operation === 'set') {
        projected = amount;
      }

      preview.textContent = (config.previewPrefix || 'New balance will be:') + ' ' + formatPoints(projected);
      preview.classList.remove('is-error');
    }

    function syncAll() {
      var operation = getSelectedOperation();
      setOptionStates(operation);
      setAmountTone(operation);
      setFormEnabledState(operation);
      syncLabels(operation);
      syncProjectedBalance(operation);
    }

    operationInputs.forEach(function (input) {
      input.addEventListener('change', syncAll);
    });
    amountInput.addEventListener('input', syncAll);
    amountInput.addEventListener('change', syncAll);
    syncAll();
  }

  function initRoleExclusions() {
    var form = document.querySelector('.credits-role-form');
    if (!form) {
      return;
    }

    var config = window.loyaltyPointsAdmin || {};
    var hiddenInput = form.querySelector('#credits_role_excluded_user_ids');
    var list = form.querySelector('#credits-role-excluded-list');
    var roleSelect = form.querySelector('#credits_role');
    var exclusionsToggle = form.querySelector('#credits_role_enable_exclusions');
    var exclusionsSection = form.querySelector('#credits-role-exclusions-section');
    var searchInput = form.querySelector('#credits-role-member-search');
    var searchResults = form.querySelector('#credits-role-member-search-results');
    var paginationLinks = form.querySelectorAll('.credits-role-users-pagination a, .credits-pagination a');

    if (!hiddenInput || !roleSelect) {
      return;
    }

    function parseIds(raw) {
      return String(raw || '')
        .split(',')
        .map(function (value) {
          return parseInt(String(value).trim(), 10);
        })
        .filter(function (value) {
          return Number.isFinite(value) && value > 0;
        });
    }

    function isExclusionsEnabled() {
      return !!(exclusionsToggle && exclusionsToggle.checked);
    }

    function getExcludeButtons() {
      return form.querySelectorAll('.credits-role-exclude-btn');
    }

    var excludedMap = {};
    if (list) {
      list.querySelectorAll('.credits-excluded-pill').forEach(function (pill) {
        var userId = parseInt(pill.getAttribute('data-user-id') || '', 10);
        if (Number.isFinite(userId) && userId > 0) {
          excludedMap[userId] = {
            id: userId,
            name: pill.getAttribute('data-user-name') || '',
            email: pill.getAttribute('data-user-email') || ''
          };
        }
      });
    }

    getExcludeButtons().forEach(function (button) {
      var userId = parseInt(button.getAttribute('data-user-id') || '', 10);
      if (Number.isFinite(userId) && userId > 0 && button.classList.contains('is-excluded')) {
        excludedMap[userId] = {
          id: userId,
          name: button.getAttribute('data-user-name') || '',
          email: button.getAttribute('data-user-email') || ''
        };
      }
    });

    parseIds(hiddenInput.value).forEach(function (id) {
      if (!excludedMap[id]) {
        excludedMap[id] = { id: id, name: 'User #' + id, email: '' };
      }
    });

    function setHiddenValue() {
      if (!isExclusionsEnabled()) {
        hiddenInput.value = '';
        hiddenInput.disabled = true;
        return;
      }
      hiddenInput.disabled = false;
      hiddenInput.value = Object.keys(excludedMap)
        .map(function (id) { return parseInt(id, 10); })
        .filter(function (id) { return Number.isFinite(id) && id > 0; })
        .sort(function (a, b) { return a - b; })
        .join(',');
    }

    function renderExcludedList() {
      if (!list) {
        return;
      }
      var ids = parseIds(Object.keys(excludedMap).join(','));
      if (!ids.length) {
        list.innerHTML = '<span class="credits-role-excluded-empty">' + escapeHtml(list.getAttribute('data-empty-label') || 'No excluded accounts.') + '</span>';
        return;
      }
      list.innerHTML = ids.map(function (id) {
        var person = excludedMap[id] || { id: id, name: 'User #' + id, email: '' };
        var label = person.name || ('User #' + id);
        if (person.email) {
          label += ' (' + person.email + ')';
        }
        return '<span class="credits-excluded-pill" data-user-id="' + id + '" data-user-name="' + escapeHtml(person.name || '') + '" data-user-email="' + escapeHtml(person.email || '') + '">' +
          '<span class="credits-excluded-pill-label">' + escapeHtml(label) + '</span>' +
          '<button type="button" class="credits-excluded-pill-remove" data-user-id="' + id + '" aria-label="Remove exclusion">&times;</button>' +
          '</span>';
      }).join('');
    }

    function syncSectionVisibility() {
      if (exclusionsSection) {
        exclusionsSection.hidden = !isExclusionsEnabled();
      }
    }

    function syncButtons() {
      getExcludeButtons().forEach(function (button) {
        var userId = parseInt(button.getAttribute('data-user-id') || '', 10);
        if (!Number.isFinite(userId) || userId <= 0) {
          return;
        }
        var isExcluded = !!excludedMap[userId];
        button.classList.toggle('is-excluded', isExcluded);
        button.setAttribute('aria-pressed', isExcluded ? 'true' : 'false');
        var label = button.querySelector('span');
        if (label) {
          label.textContent = isExcluded ? 'Excluded' : 'Exclude';
        }
      });
    }

    function syncPaginationLinks() {
      paginationLinks.forEach(function (link) {
        var href = link.getAttribute('href');
        if (!href) {
          return;
        }
        var url;
        try {
          url = new URL(href, window.location.origin);
        } catch (error) {
          return;
        }
        if (isExclusionsEnabled()) {
          url.searchParams.set('exclude_users', '1');
          if (hiddenInput.value.trim()) {
            url.searchParams.set('excluded_user_ids', hiddenInput.value.trim());
          }
        } else {
          url.searchParams.delete('exclude_users');
          url.searchParams.delete('excluded_user_ids');
        }
        link.setAttribute('href', url.toString());
      });
    }

    function syncAll() {
      syncSectionVisibility();
      setHiddenValue();
      renderExcludedList();
      syncButtons();
      syncPaginationLinks();
    }

    var searchRequestId = 0;
    function clearSearchResults() {
      if (searchResults) {
        searchResults.innerHTML = '';
        searchResults.classList.remove('is-visible');
      }
    }

    function renderSearchResults(results) {
      if (!searchResults) {
        return;
      }
      if (!Array.isArray(results) || !results.length) {
        searchResults.innerHTML = '<div class="credits-role-member-search-empty">' + escapeHtml(config.roleMemberSearchNoResults || 'No matching users in this role.') + '</div>';
        searchResults.classList.add('is-visible');
        return;
      }
      searchResults.innerHTML = '<ul class="credits-role-member-search-list">' + results.map(function (result) {
        var userId = parseInt(result.id, 10);
        var name = result.name || ('User #' + userId);
        var email = result.email || '';
        var excluded = !!excludedMap[userId];
        return '<li class="credits-role-member-search-item">' +
          '<div class="credits-role-member-search-meta"><span class="credits-role-member-search-name">' + escapeHtml(name) + '</span><span class="credits-role-member-search-email">' + escapeHtml(email) + '</span></div>' +
          '<button type="button" class="button credits-role-exclude-btn' + (excluded ? ' is-excluded' : '') + '" data-user-id="' + userId + '" data-user-name="' + escapeHtml(name) + '" data-user-email="' + escapeHtml(email) + '" aria-pressed="' + (excluded ? 'true' : 'false') + '">' +
          '<i class="fa-duotone fa-user-minus" aria-hidden="true"></i> <span>' + escapeHtml(excluded ? 'Excluded' : 'Exclude') + '</span></button></li>';
      }).join('') + '</ul>';
      searchResults.classList.add('is-visible');
    }

    var doRoleSearch = debounce(function () {
      if (!searchInput || !searchResults || !isExclusionsEnabled()) {
        clearSearchResults();
        return;
      }
      var term = searchInput.value.trim();
      if (term.length < 2 || !config.ajaxUrl || !config.roleMemberSearchNonce || !roleSelect.value) {
        clearSearchResults();
        return;
      }
      searchRequestId += 1;
      var currentRequest = searchRequestId;
      var query = '?action=' + encodeURIComponent(config.roleMemberSearchAction || 'square_loyalty_points_role_member_search') +
        '&_wpnonce=' + encodeURIComponent(config.roleMemberSearchNonce) +
        '&role=' + encodeURIComponent(roleSelect.value) +
        '&term=' + encodeURIComponent(term);
      fetch(config.ajaxUrl + query, { credentials: 'same-origin' })
        .then(function (response) { return response.json(); })
        .then(function (payload) {
          if (currentRequest === searchRequestId && payload && payload.success && payload.data) {
            renderSearchResults(payload.data.results || []);
          }
        })
        .catch(clearSearchResults);
    }, 220);

    form.addEventListener('click', function (event) {
      var remove = event.target.closest('.credits-excluded-pill-remove');
      if (remove && list && list.contains(remove)) {
        event.preventDefault();
        var removeId = parseInt(remove.getAttribute('data-user-id') || '', 10);
        if (Number.isFinite(removeId) && removeId > 0) {
          delete excludedMap[removeId];
          syncAll();
        }
        return;
      }
      var toggle = event.target.closest('.credits-role-exclude-btn');
      if (!toggle || !form.contains(toggle) || !isExclusionsEnabled()) {
        return;
      }
      event.preventDefault();
      var userId = parseInt(toggle.getAttribute('data-user-id') || '', 10);
      if (!Number.isFinite(userId) || userId <= 0) {
        return;
      }
      if (excludedMap[userId]) {
        delete excludedMap[userId];
      } else {
        excludedMap[userId] = {
          id: userId,
          name: toggle.getAttribute('data-user-name') || '',
          email: toggle.getAttribute('data-user-email') || ''
        };
      }
      syncAll();
    });

    roleSelect.addEventListener('change', function () {
      var url = new URL(window.location.href);
      url.searchParams.set('page', config.pageSlug || 'square-loyalty-points');
      url.searchParams.set('tab', 'role');
      if (roleSelect.value) {
        url.searchParams.set('selected_role', roleSelect.value);
      } else {
        url.searchParams.delete('selected_role');
      }
      url.searchParams.delete('role_users_page');
      url.searchParams.delete('role_activity_page');
      if (isExclusionsEnabled()) {
        url.searchParams.set('exclude_users', '1');
        if (hiddenInput.value.trim()) {
          url.searchParams.set('excluded_user_ids', hiddenInput.value.trim());
        }
      } else {
        url.searchParams.delete('exclude_users');
        url.searchParams.delete('excluded_user_ids');
      }
      window.location.href = url.toString();
    });

    if (exclusionsToggle) {
      exclusionsToggle.addEventListener('change', function () {
        if (!exclusionsToggle.checked) {
          excludedMap = {};
          if (searchInput) {
            searchInput.value = '';
          }
          clearSearchResults();
        }
        syncAll();
      });
    }

    if (searchInput) {
      searchInput.addEventListener('input', doRoleSearch);
      searchInput.setAttribute('placeholder', config.roleMemberSearchPlaceholder || 'Search role members to exclude');
    }

    syncAll();
  }

  function initLiveSearch() {
    var input = document.getElementById('credits-live-search');
    var resultsBox = document.getElementById('credits-live-results');
    var config = window.loyaltyPointsAdmin || {};
    if (!input || !resultsBox || !config.ajaxUrl || !config.searchNonce) {
      return;
    }

    var requestId = 0;
    function clearResults() {
      resultsBox.innerHTML = '';
      resultsBox.classList.remove('is-visible');
    }

    function renderResults(results) {
      if (!Array.isArray(results) || !results.length) {
        clearResults();
        return;
      }
      resultsBox.innerHTML = '<ul>' + results.map(function (result) {
        var label = (result.name || '') + ' (' + (result.email || '') + ')';
        return '<li><a href="' + escapeHtml(result.url || '#') + '"><i class="fa-duotone fa-user" aria-hidden="true"></i> ' + escapeHtml(label) + '</a></li>';
      }).join('') + '</ul>';
      resultsBox.classList.add('is-visible');
    }

    var doSearch = debounce(function () {
      var term = input.value.trim();
      if (term.length < 2) {
        clearResults();
        return;
      }
      requestId += 1;
      var currentRequest = requestId;
      var query = '?action=' + encodeURIComponent(config.searchAction || 'square_loyalty_points_user_search') +
        '&_wpnonce=' + encodeURIComponent(config.searchNonce) +
        '&term=' + encodeURIComponent(term);
      fetch(config.ajaxUrl + query, { credentials: 'same-origin' })
        .then(function (response) { return response.json(); })
        .then(function (payload) {
          if (currentRequest === requestId && payload && payload.success && payload.data) {
            renderResults(payload.data.results || []);
          }
        })
        .catch(clearResults);
    }, 220);

    input.addEventListener('input', doSearch);
    document.addEventListener('click', function (event) {
      if (!resultsBox.contains(event.target) && event.target !== input) {
        clearResults();
      }
    });
  }

  function initInputDecorations() {
    document.querySelectorAll('.credits-input-decor').forEach(function (wrapper) {
      var field = wrapper.querySelector('input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]), select, textarea');
      if (!field) {
        return;
      }
      var initialValue = typeof field.value === 'string' ? field.value.trim() : field.value;
      function syncFilled() {
        var value = typeof field.value === 'string' ? field.value.trim() : field.value;
        wrapper.classList.toggle('is-filled', value !== initialValue);
      }
      field.addEventListener('input', syncFilled);
      field.addEventListener('change', syncFilled);
      syncFilled();
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    initRoleCountInfo();
    initOperationControls('.credits-manage-form', {
      amountSelector: '#credits_amount',
      amountWrapSelector: '.credits-manage-amount-wrap',
      amountLabelSelector: '#credits_amount_label',
      noteSelector: '#credits_note',
      submitSelector: '#credits_apply_change',
      amountLabel: 'Points',
      setAmountLabel: 'New balance',
      isRole: false
    });
    initOperationControls('.credits-role-form', {
      amountSelector: '#credits_role_amount',
      amountWrapSelector: '.credits-role-amount-wrap',
      amountLabelSelector: '#credits_role_amount_label',
      noteSelector: '#credits_role_note',
      submitSelector: '#credits_apply_role',
      amountLabel: 'Points per user',
      setAmountLabel: 'Set balance per user',
      isRole: true
    });
    initRoleExclusions();
    initLiveSearch();
    initInputDecorations();
  });
})();
