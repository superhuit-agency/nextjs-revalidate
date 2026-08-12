export {}

declare global {
	interface Window {
		nextjs_revalidate: {
			url: string;
			nonce: string;
		};
		nextjs_revalidate_notice?: {
			status: "success" | "error";
			message: string;
		};
		wp?: {
			data?: {
				dispatch: (store: string) => {
					createNotice: (
						status: string,
						message: string,
						options?: Record<string, unknown>
					) => void;
				};
			};
		};
	}
}
