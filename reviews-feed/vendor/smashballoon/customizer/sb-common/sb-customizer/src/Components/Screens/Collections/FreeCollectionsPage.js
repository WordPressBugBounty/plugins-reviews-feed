import CollectionEmpty from "./Sections/Single/CollectionEmpty";

const FreeCollectionsPage = () => {

	return (
		<section className='sb-full-wrapper sb-fs sb-fs'>
			<section className='sb-small-wrapper'>
				<CollectionEmpty
					modal={false}
					free={true}
				/>
			</section>
		</section>
	)
}

export default FreeCollectionsPage;