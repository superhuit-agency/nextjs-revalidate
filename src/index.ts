const INTERVAL = 1; // seconds

let checkProgressInterval: NodeJS.Timer;

function getNotice() {
	return document.querySelector('.nextjs-revalidate-purge-all__notice');
}

function init() {
	if ( !getNotice() ) return;

	if ( !window?.nextjs_revalidate?.url || !window?.nextjs_revalidate?.nonce ) return;

	checkProgressInterval = setInterval(checkRevalidateAllProgress, 1000 * INTERVAL);
}

function checkRevalidateAllProgress() {
	const url = new URL( window.nextjs_revalidate.url )
	url.searchParams.append( 'action', 'nextjs-revalidate-purge-all-progress' )
	url.searchParams.append( '_ajax_nonce', window.nextjs_revalidate.nonce )

	fetch(url)
		.then( (res) => res.json() )
		.then( ({ data }) => {
			const notice = getNotice();
			const progress = notice.querySelector('.nextjs-revalidate-purge-all__progress');

			if ( data.status === 'running' ) progress.textContent = `${data.progress}% (${data.done}/${data.total})`;
			else {
				if ( data.status === 'done' ) {
					notice.classList.remove('notice-info')
					progress.textContent = `${data.progress}% (${data.done}/${data.total}) 🎉`;
					notice.classList.add('notice-success')
				}
				else {
					notice.parentElement.removeChild( notice );
				}
				clearInterval( checkProgressInterval );
			}
		})
		.catch( err => {
			console.error( err );
		})
}


window.addEventListener('load', function() {
	init();
})
