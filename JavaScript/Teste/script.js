function printaMensagem() {
    console.log("A página foi carregada")
}


window.addEventListener("load", printaMensagem)

addEventListener("click", (Event) => {
    console.log(Event)
})