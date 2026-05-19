(() => {
  const form = document.getElementById('enrollmentForm');
  const matrix = document.getElementById('enrollmentMatrix');
  const showTotalsToggle = document.getElementById('showTotals');
  const unsavedStatus = document.getElementById('unsavedStatus');
  const formErrors = document.getElementById('formErrors');

  if (!form || !matrix) return;

  let dirty = false;

  const inputs = Array.from(matrix.querySelectorAll('.matrix-input'));

  function markDirty() {
    if (!dirty) {
      dirty = true;
      unsavedStatus.textContent = 'Unsaved changes';
      unsavedStatus.style.color = '#d97706';
      unsavedStatus.style.background = '#fff7ed';
    }
  }

  function clearDirty() {
    dirty = false;
    unsavedStatus.textContent = 'Saved';
    unsavedStatus.style.color = '#0f766e';
    unsavedStatus.style.background = '#eef9f7';
  }

  function updateTotalsVisibility() {
    const show = showTotalsToggle.checked;
    matrix.querySelectorAll('[data-total-col]').forEach(el => {
      el.style.display = show ? '' : 'none';
    });
    matrix.querySelectorAll('[data-row-total]').forEach(el => {
      el.style.display = show ? '' : 'none';
    });
  }

  function parseNum(value) {
    const n = Number(value);
    return Number.isFinite(n) && n >= 0 ? n : 0;
  }

  function recalcRowTotals() {
    matrix.querySelectorAll('tbody tr').forEach(row => {
      const total = Array.from(row.querySelectorAll('.matrix-input')).reduce((sum, input) => sum + parseNum(input.value), 0);
      const totalCell = row.querySelector('[data-row-total]');
      if (totalCell) totalCell.textContent = String(total);
    });
  }

  function validateCell(input) {
    input.classList.remove('error', 'warning');
    if (input.value === '') return true;

    const n = Number(input.value);
    if (!Number.isFinite(n) || n < 0 || !Number.isInteger(n)) {
      input.classList.add('error');
      return false;
    }
    if (n > 2000) {
      input.classList.add('warning');
    }
    return true;
  }

  function validateForm() {
    formErrors.textContent = '';
    let ok = true;
    inputs.forEach(input => {
      if (!validateCell(input)) ok = false;
    });
    if (!ok) {
      formErrors.textContent = 'Please fix highlighted values. Use non-negative whole numbers only.';
    }
    return ok;
  }

  function cellPosition(input) {
    const td = input.closest('td');
    const tr = td?.parentElement;
    if (!td || !tr) return null;
    return {
      rowIndex: tr.sectionRowIndex,
      colIndex: td.cellIndex,
    };
  }

  function findInputByPosition(rowIndex, colIndex) {
    const row = matrix.tBodies[0]?.rows[rowIndex];
    if (!row) return null;
    const cell = row.cells[colIndex];
    if (!cell) return null;
    return cell.querySelector('.matrix-input');
  }

  function moveFocus(currentInput, key) {
    const pos = cellPosition(currentInput);
    if (!pos) return;

    let nextRow = pos.rowIndex;
    let nextCol = pos.colIndex;

    if (key === 'ArrowRight') nextCol += 1;
    if (key === 'ArrowLeft') nextCol -= 1;
    if (key === 'ArrowDown') nextRow += 1;
    if (key === 'ArrowUp') nextRow -= 1;

    const nextInput = findInputByPosition(nextRow, nextCol);
    if (nextInput) {
      nextInput.focus();
      nextInput.select();
    }
  }

  inputs.forEach(input => {
    input.addEventListener('input', () => {
      markDirty();
      validateCell(input);
      recalcRowTotals();
    });

    input.addEventListener('keydown', (e) => {
      if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key)) {
        e.preventDefault();
        moveFocus(input, e.key);
      }
    });
  });

  showTotalsToggle?.addEventListener('change', updateTotalsVisibility);

  form.addEventListener('submit', (e) => {
    if (!validateForm()) {
      e.preventDefault();
      return;
    }
    clearDirty();
  });

  window.addEventListener('beforeunload', (e) => {
    if (!dirty) return;
    e.preventDefault();
    e.returnValue = '';
  });

  updateTotalsVisibility();
  recalcRowTotals();
})();
