import { __ } from '@wordpress/i18n'
import { useContext, useState } from 'react';
import SbUtils from '../../../../Utils/SbUtils';
import Button from '../../../Common/Button';
import Select from '../../../Common/Select';

const ManageExportFeed = ( props ) => {
    const { sbCustomizer } = useContext( SbUtils.getCurrentContext() );

    const [selectedFeed, setSelectedFeed] = useState( false );

    const exportFeed = () => {
        if (selectedFeed !== "-1" && selectedFeed !== false) {
            const feed = SbUtils.findElementById( sbCustomizer?.feedsList, 'id', selectedFeed );
            if( feed ){
                SbUtils.exportStringToFile( JSON.stringify( feed ) ,`sbr-feed-${selectedFeed}.json` )
            }
        }
    }

    return (
        <>
        <div className='sb-settings-input-ctn'>
            <Select
                value={ selectedFeed }
                size='medium'
                onChange={ ( event ) => {
                    setSelectedFeed( event.currentTarget.value )
                }}
            >
                <option value={false}>{  __( 'Select Feed', 'sb-customizer' ) } </option>
                {
                    sbCustomizer?.feedsList.map( feed => {
                        return (
                            <option key={feed.id} value={feed.id}>{ feed.feed_name }</option>
                        )
                    } )
                }
            </Select>
            <Button
                text={  __( 'Export', 'sb-customizer' ) }
                type='secondary'
                size='medium'
                icon='download'
                iconSize='11'
                onClick={ () => {
                    exportFeed()
                } }
            />
        </div>
        </>
    )
}

export default ManageExportFeed;