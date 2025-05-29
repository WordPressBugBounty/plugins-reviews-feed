import { useContext, useState } from 'react'
import { __ } from '@wordpress/i18n'
import Button from '../Common/Button';
import SbUtils from '../../Utils/SbUtils';
import Input from '../Common/Input';

const CreateCollectionModal = (props) => {
	const [ collectionName, setCollection ] = useState('')


	return (
		<div className="sb-createcollection-modal sb-fs">
            <div className="sb-createcollection-modal-heading sb-fs">
                <h4 className='sb-h4'>{ __( 'Create Collection', 'reviews-feed' ) }<span></span></h4>
            </div>
            <div className='sb-createcollection-form sb-collection-form-row sb-fs' data-rows='1'>
				<span className='sb-fs sb-small-p'>{ __( 'Name your Collection', 'reviews-feed' ) }</span>
				<Input
					type='text'
					size='medium'
					placeholder={ __( 'Eg. Homepage Reviews', 'reviews-feed' ) }
					value={collectionName}
					onChange={(event) => {
						setCollection(event.currentTarget.value)
					}}
				/>
			</div>
			<div className='sb-embedfeed-modal-actbtns'>
            	<Button
                	size='medium'
                    type='secondary'
                    text={ __( 'Cancel', 'reviews-feed' ) }
                    onClick={ () => {
						props.onCancel()
                    }}
                />
                <Button
                	size='medium'
                	type='primary'
                    text={ __( 'Create Collection', 'reviews-feed' ) }
                    icon='success'
                    onClick={ () => {
						props.createNewCollection(collectionName)
					}}
                />
            </div>
		</div>
	)
}

export default CreateCollectionModal;