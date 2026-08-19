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
		nextjs_revalidate_degraded_notice?: {
			status: "error";
			message: string;
			actions?: { label: string; url: string }[];
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
