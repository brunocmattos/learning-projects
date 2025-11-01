const input = document.getElementById("new-item")
const ulExistente = document.querySelector("ul");
const aviso = document.querySelector('.aviso-remover');
const textoAviso = aviso.querySelector('span');
const cancelarAviso = aviso.querySelector('img[src="assets/cancel.svg"]');

function adicionarItem(texto) {
  const li = document.createElement("li");
  li.className = "item-list flex";
  li.innerHTML = `
    <input type="checkbox">
    <span>${texto}</span>
    <img src="assets/Frame-3.svg" alt="Excluir item">
  `;
  ulExistente.appendChild(li);
}

function mostrarAviso(nomeItem) {
  textoAviso.textContent = `"${nomeItem}" foi removido da lista`;
  aviso.style.display = 'flex';
  
  // Some automaticamente depois de 5 segundos
  setTimeout(() => {
    aviso.style.display = 'none';
  }, 5000);
}

// Quando clicar em qualquer lugar da página
document.addEventListener('click', function(e) {
  // Se clicou na imagem de excluir
  if (e.target.src && e.target.src.includes('Frame-3.svg')) {
    const nomeItem = e.target.previousElementSibling.textContent;
    e.target.parentElement.remove();
    mostrarAviso(nomeItem);
  }
  
  // Se clicou no X para fechar o aviso
  if (e.target.src && e.target.src.includes('cancel.svg')) {
    aviso.style.display = 'none';
  }
});

// Quando mudar qualquer checkbox
document.addEventListener('change', function(e) {
  if (e.target.type === 'checkbox') {
    const texto = e.target.nextElementSibling;
    texto.style.textDecoration = e.target.checked ? 'line-through' : 'none';
  }
});

// Quando enviar o formulário
document.onsubmit = function(event) { 
  event.preventDefault()
  adicionarItem(input.value)
  input.value = ""
}