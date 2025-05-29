import { __ } from '@wordpress/i18n'
import Button from '../../Common/Button';
import SbUtils from '../../../Utils/SbUtils';
import { useContext, useState } from 'react';
import CollectionsList from './Sections/CollectionsList';
import Collection from './Sections/Single/Collection';
import FormsSubmissionHome from './Sections/FormsIntegration/FormsSubmissionHome';

const ProCollectionsPage = () => {
	const currentContext = SbUtils.getCurrentContext();
    const {
        sbCustomizer,
        slSection
    } = useContext( currentContext );

	return (
        <section className='sb-full-wrapper sb-fs'>
			{
                sbCustomizer?.adminNoticeContent !== null &&
                <section className='sb-fs'
                        dangerouslySetInnerHTML={{__html: sbCustomizer?.adminNoticeContent }}></section>
            }
            {
                slSection.collectionSectionActive === 'list' &&
    			<CollectionsList />
            }
			{
                slSection.collectionSectionActive === 'single' &&
                <Collection />
            }
            {
                slSection.collectionSectionActive === 'formsSubmissions' &&
                <FormsSubmissionHome />
            }
		</section>
	)
}

export default ProCollectionsPage;
