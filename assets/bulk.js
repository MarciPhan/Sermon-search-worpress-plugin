(() => {
  'use strict';
  const form = document.getElementById('bsw-bulk-form');
  const status = document.getElementById('bsw-bulk-status');
  const bar = document.getElementById('bsw-bulk-progress');
  const button = document.getElementById('bsw-bulk-button');
  if (!window.BSWBulk) return;

  function setText(message, isError = false) {
    status.textContent = message;
    status.style.color = isError ? '#b32d2e' : '';
  }
  async function send(action, data = {}) {
    const payload = new FormData();
    payload.append('action', action);
    payload.append('nonce', BSWBulk.nonce);
    Object.entries(data).forEach(([key, val]) => payload.append(key, val));
    const res = await fetch(BSWBulk.url, {method: 'POST', body: payload, credentials: 'same-origin'});
    const result = await res.json();
    if (!result.success) throw new Error(result.data?.message || 'Chyba importu (HTTP ' + res.status + ')');
    return result.data;
  }

  const refsButton = document.getElementById('bsw-refs-button');
  const refsBar = document.getElementById('bsw-refs-progress');
  const refsStatus = document.getElementById('bsw-refs-status');
  if (refsButton) refsButton.addEventListener('click', async () => {
    refsButton.disabled = true;
    refsBar.hidden = false;
    refsStatus.style.color = '';
    let offset = 0, found = 0, data;
    try {
      do {
        data = await send('bsw_refs_step', {offset});
        offset = data.offset;
        found += data.refs;
        refsBar.max = Math.max(data.total, 1);
        refsBar.value = offset;
        refsStatus.textContent = `Zpracováno ${Math.min(offset, data.total)}/${data.total} kázání, nalezeno ${found} odkazů.`;
      } while (!data.done);
      refsStatus.textContent = `Hotovo: ${data.total} kázání, ${found} odkazů.`;
    } catch (error) {
      refsStatus.textContent = error.message + ' Přepočet lze bezpečně spustit znovu.';
      refsStatus.style.color = '#b32d2e';
    } finally {
      refsButton.disabled = false;
    }
  });

  const indexButton = document.getElementById('bsw-index-button');
  const indexBar = document.getElementById('bsw-index-progress');
  const indexStatus = document.getElementById('bsw-index-status');
  if (indexButton) indexButton.addEventListener('click', async () => {
    indexButton.disabled = true;
    indexBar.hidden = false;
    indexStatus.style.color = '';
    let restart = 1, data;
    try {
      do {
        data = await send('bsw_index_step', {restart});
        restart = 0;
        indexBar.max = Math.max(data.total, 1);
        indexBar.value = data.total - data.left;
        indexStatus.textContent = data.done ? 'Hotovo – vyhledávání teď najde i jiné tvary slov.' : `Zbývá ${data.left} z ${data.total} kázání…`;
      } while (!data.done);
    } catch (error) {
      indexStatus.textContent = error.message + ' Přepočet lze bezpečně spustit znovu (pokračuje, kde skončil).';
      indexStatus.style.color = '#b32d2e';
    } finally {
      indexButton.disabled = false;
    }
  });

  if (form) form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const files = Array.from(form.querySelector('input[type=file]').files);
    if (!files.length) return;
    button.disabled = true;
    bar.hidden = false;
    bar.value = 0;
    let videos = 0, segments = 0, errors = 0;
    const failed = [];
    for (const [i, file] of files.entries()) {
      const label = files.length > 1 ? `ZIP ${i + 1}/${files.length} (${file.name}): ` : '';
      try {
        setText(label + 'nahrávám…');
        const started = await send('bsw_bulk_start', {archive: file});
        let data;
        do {
          data = await send('bsw_bulk_step', {job: started.job});
          bar.max = data.total * files.length;
          bar.value = data.total * i + data.processed;
          setText(`${label}import ${data.processed}/${data.total} videí, celkem hotovo ${videos + data.imported}.`);
        } while (!data.done);
        videos += data.imported; segments += data.segments; errors += data.errors_count;
      } catch (error) {
        failed.push(file.name + ': ' + error.message);
      }
    }
    const tail = failed.length ? ' Nepovedlo se: ' + failed.join(' | ') + ' – tyto ZIPy lze bezpečně nahrát znovu.' : '';
    setText(`Hotovo: ${files.length} ZIPů, ${videos} videí, ${segments} úseků, ${errors} chyb.${tail}`, failed.length > 0);
    if (!failed.length) form.reset();
    button.disabled = false;
  });
})();
