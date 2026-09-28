/**
 * Swaps in the latest webcam image without reloading the page.
 *
 * Polls the JSON status endpoint while the page is visible and checks once more
 * whenever the page becomes visible again, e.g. when a phone is unlocked.
 */
class LalaWebcam extends HTMLElement {
    connectedCallback() {
        this.interval = Number(this.dataset.refreshInterval) * 60 * 1000;
        if (!this.dataset.statusUri || !(this.interval > 0)) {
            return;
        }

        this.onVisibilityChange = () => (document.hidden ? this.stop() : this.refreshAndSchedule());
        document.addEventListener('visibilitychange', this.onVisibilityChange);
        this.schedule();
    }

    disconnectedCallback() {
        document.removeEventListener('visibilitychange', this.onVisibilityChange);
        this.stop();
    }

    schedule() {
        this.stop();
        this.timer = setTimeout(() => this.refreshAndSchedule(), this.interval);
    }

    stop() {
        clearTimeout(this.timer);
    }

    async refreshAndSchedule() {
        try {
            await this.refresh();
        } catch (error) {
            console.warn('Could not refresh webcam image', error);
        }
        if (!document.hidden) {
            this.schedule();
        }
    }

    async refresh() {
        const response = await fetch(this.dataset.statusUri, {headers: {Accept: 'application/json'}});
        if (!response.ok) {
            return;
        }
        const snapshot = await response.json();
        const uri = new URL(snapshot.uri, document.baseURI).href;

        let image = this.querySelector('img');
        if (image?.src === uri) {
            return;
        }

        // Load the new image completely before showing it, so it never renders half way
        const preloaded = new Image();
        preloaded.src = uri;
        await preloaded.decode();

        if (!image) {
            image = document.createElement('img');
            image.alt = this.dataset.alt ?? '';
            this.querySelector('p')?.remove();
            this.prepend(image);
        }
        image.width = snapshot.width;
        image.height = snapshot.height;
        image.src = uri;
    }
}

if (!customElements.get('lala-webcam')) {
    customElements.define('lala-webcam', LalaWebcam);
}
