const gallery = document.querySelector("#gym-gallery");
const motionButton = gallery?.querySelector("[data-gallery-motion]");
const photoDialog = gallery?.querySelector("[data-gallery-dialog]");
const dialogImage = photoDialog?.querySelector("[data-gallery-dialog-image]");
const dialogCaption = photoDialog?.querySelector("[data-gallery-dialog-caption]");

motionButton?.addEventListener("click", () => {
  const paused = gallery.classList.toggle("gallery-motion-paused");
  motionButton.setAttribute("aria-pressed", String(paused));
  motionButton.setAttribute("aria-label", paused
    ? window.smartGymTranslations?.[document.documentElement.lang]?.galleryResume || "Resume gallery movement"
    : window.smartGymTranslations?.[document.documentElement.lang]?.galleryPause || "Pause gallery movement");
  gallery.querySelector("[data-gallery-pause-icon]").hidden = paused;
  gallery.querySelector("[data-gallery-play-icon]").hidden = !paused;
  gallery.querySelector("[data-gallery-pause-label]").hidden = paused;
  gallery.querySelector("[data-gallery-resume-label]").hidden = !paused;
});

gallery?.addEventListener("click", (event) => {
  const photoButton = event.target.closest("[data-gallery-photo]");
  if (photoButton && !photoButton.closest('[aria-hidden="true"]')) {
    const image = photoButton.querySelector("img");
    if (!image || !photoDialog || !dialogImage || !dialogCaption) return;
    dialogImage.src = image.currentSrc || image.src;
    dialogCaption.textContent = photoButton.querySelector("span")?.textContent.trim() || "Plus Ultra Gym";
    photoDialog.showModal();
    return;
  }

  if (event.target.closest("[data-gallery-close]")) photoDialog?.close();
  if (event.target === photoDialog) photoDialog.close();
});
