export {}

declare global {
	interface Window {
		nextjs_revalidate: {
			url: string;
			nonce: string;
		};
	}
}
